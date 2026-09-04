# Модуль «Бюджет Общего дома» — расходы, учёт по статьям, отчётность для жителей

Дата: 2026-09-04
Репозиторий: `digistaff-team/skaz-kray-astro`, приложение `residents/` (прод: `/var/www/skaz-residents`)

## Цель

В разделе **«Попечительский совет»** (`/sovet/*`) skaz-kray.ru — модуль учёта денег Общего дома:
члены совета вносят приход и расход по статьям, а жители поселения видят наглядный
**помесячный** read-only отчёт, куда идут их взносы. Это заменяет текущий
плейсхолдер «Бухгалтерия» (ссылка на Google Docs) на главной совета реальной страницей.

## Утверждённые требования

- **Двусторонний учёт**: приход и расход. Отчёт показывает баланс.
- **Помесячная логика**: остаток считается за каждый месяц (собрано − потрачено месяца),
  поскольку доход поступает ежемесячно. **Остаток месяца может быть отрицательным** —
  это нормальное состояние (крупные разовые траты превышают месячный доход).
- **Статьи — управляемый справочник** (админ совета ведёт список). Не свободный ввод.
  - Приход, стартовые статьи: «Из Фонда общего дома», «Коммерческая аренда», «Школа»
    (админ может добавлять другие).
  - Расход, стартовые статьи (примерные, админ правит): «Дороги и въезд», «Электрика»,
    «Вывоз мусора», «Праздники», «Инвентарь».
- **Вносят все члены совета** (совместная модель, как доска задач). Управление
  справочником статей — только админ совета.
- **Фото чека** — опционально, к расходной операции.
- **Наглядный вид отчёта** (утверждён в макете): помесячная таблица
  (Месяц / Собрано / Потрачено / Остаток месяца ±) со всеми месяцами с данными (новые
  сверху) и итогом за всё время → разбивка расходов выбранного месяца **полосками**
  (не кольцом) → лента операций месяца. **Фильтр по годам не нужен.**
- **Учёт ведётся с июля 2026** — первый месяц данных. Таблица естественно начинается с
  первого внесённого месяца; отдельных ограничений в коде не требуется.
- **Доступ жителей**: страница read-only, гард `Auth::requireLogin()` (аккаунт семьи в
  разделе «Жители»). Не выносится в публичное меню сайта — внутренний раздел за входом.

## Архитектура

Встраивается в существующее PHP-приложение (чистый PHP 8.3 + PDO, тонкий роутер,
шаблоны-партиалы, namespace `SkazResidents`, БД MariaDB `skazkray_residents`,
тесты на SQLite in-memory). Никакого JS — статичная вёрстка (полоски по статьям — CSS),
как в остальном разделе. Стиль — фирменные токены (`--green #008757`, `--ochre #c98a3a`,
`--paper`, PT Serif/PT Sans, карточки `.res-card`).

### Данные (новый файл `residents/config/council-ledger-schema.sql`)

Накатывается вручную ОДИН раз в ту же БД `skazkray_residents` (правило раздела: прод-схема
руками). Содержит `CREATE TABLE` + `INSERT` стартовых статей.

**`council_ledger_categories`** — справочник статей:

| поле | тип | смысл |
|------|-----|-------|
| id | INT UNSIGNED PK AI | |
| kind | VARCHAR(16) | `income` \| `expense` |
| name | VARCHAR(160) | название статьи |
| position | INT DEFAULT 0 | порядок в списках |
| is_active | TINYINT(1) DEFAULT 1 | 0 = архивирована (не предлагается при вводе) |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP | |

Индекс `(kind, is_active, position)`.

**`council_ledger_entries`** — операции:

| поле | тип | смысл |
|------|-----|-------|
| id | INT UNSIGNED PK AI | |
| kind | VARCHAR(16) | `income` \| `expense` (дублирует kind статьи для простых выборок) |
| category_id | INT UNSIGNED | FK → council_ledger_categories(id) |
| amount | DECIMAL(12,2) | сумма, руб, всегда ≥ 0 |
| entry_date | DATE | дата операции (по ней группировка по месяцам) |
| note | VARCHAR(300) | описание |
| author | VARCHAR(160) | имя внёсшего члена совета |
| created_at | DATETIME DEFAULT CURRENT_TIMESTAMP | |

Индекс `(entry_date)` и `(category_id)`. FK `ON DELETE RESTRICT` для category_id
(в MariaDB; в SQLite-тестах целостность обеспечивает код репозитория).

**Фото чека** — существующая таблица `images`, без изменений:
`owner_type = 'expense'`, `owner_id = entry.id`, `path = <имя файла из Upload::saveImage>`.
Загрузка — `Upload::saveImage($_FILES[...], $uploadsDir)` + `ImageRepository::add(...)`.
Удаление операции чистит её изображения (`ImageRepository::deleteFor('expense', id)`) и файлы.

**Деньги/агрегация**: `DECIMAL(12,2)`; ввод парсится `max(0,(float)str_replace(',','.',...))`
как в задачах. Итоги — `SUM(...) ... GROUP BY` (портируется MariaDB↔SQLite). Никаких
`MAX(a,b)`/`GREATEST`/`LEAST` в SQL (правило раздела) — сравнения/сортировки в PHP.
Помесячная группировка — по `substr(entry_date,1,7)` (`YYYY-MM`) в PHP, чтобы не зависеть
от диалектных функций дат.

### Классы (по паттернам раздела)

- **`Repository/CouncilCategoryRepository.php`** — CRUD справочника: `listByKind($kind, $onlyActive)`,
  `create`, `rename`, `setActive`, `find`, `countEntries($categoryId)`. Архивация вместо
  удаления, если по статье есть операции.
- **`Repository/CouncilLedgerRepository.php`** — операции: `create`, `find`, `updateFields`,
  `delete`, `listForMonth($ym)`, `allEntries()`; агрегации `sumsByMonth()` (собрано/потрачено
  по всем месяцам), `expenseByCategory($ym)` (разбивка расходов месяца), `monthsWithData()`
  (список месяцев `YYYY-MM`, новые сверху). Сортировки/группировки по месяцам — в PHP
  (по `substr(entry_date,1,7)`).
- **`Service/LedgerReport.php`** — собирает модель отчёта из репозиториев (общий источник
  правды для совета и жителей): помесячная таблица `[{ym, label, income, expense, balance}]`
  (все месяцы с данными, новые сверху) + итог за всё время; выбранный месяц (по `?mesyac`,
  дефолт — последний месяц с данными); разбивка расходов выбранного месяца (полоски: name,
  sum, pct); лента операций месяца с флагом наличия чека. Используется обоими контроллерами —
  цифры у совета и жителей идентичны.
- **`Controller/Council/LedgerController.php`** — страница совета `/sovet/buhgalteriya`:
  показ отчёта (через `LedgerReport`) + форма ввода операции + inline-правка/удаление;
  управление статьями (admin-гард). Гарды: `CouncilAuth::requireLogin()` на всё,
  `CouncilAuth::requireAdmin()` на операции со справочником. CSRF на всех POST.
- **`Controller/BudgetController.php`** — read-only отчёт жителей `/poselenie/byudzhet`:
  `Auth::requireLogin()`, рендер той же модели `LedgerReport` в житель-layout, без форм.

### Шаблоны

- `templates/council/ledger.php` — отчёт + форма операции (`<details>`: переключатель
  приход/расход, `<select>` статьи по типу, сумма, дата, заметка, `<input type=file>` чек)
  + лента операций с формами правки/удаления. Layout `council/layout`.
- `templates/council/categories.php` — управление справочником (добавить/переименовать/
  архивировать/вернуть), admin. Layout `council/layout`.
- `templates/budget/report.php` — read-only отчёт жителей. Layout `layout` (портал жителей).
- `templates/partials/ledger_report.php` — переиспользуемый партиал наглядной части
  (помесячная таблица, полоски по статьям, лента операций), подключается и советом, и
  жителями; правку показывает только при переданном флаге `$editable`.
- Новые CSS-классы (`.ledger-*`, `.budget-*`, `.months`, `.bar-*`) — дописать в
  `public/assets/residents.css` (значения из утверждённого макета).

### Маршруты (`residents/public/index.php`)

Специфичные пути — до generic `{id}` (правило роутера).

Совет:
- `GET  /sovet/buhgalteriya` → `LedgerController::index`
- `POST /sovet/buhgalteriya/operaciya` → `create`
- `POST /sovet/buhgalteriya/operaciya/{id}/obnovit` → `update`
- `POST /sovet/buhgalteriya/operaciya/{id}/udalit` → `delete`
- `GET  /sovet/buhgalteriya/statyi` → `categories` (admin)
- `POST /sovet/buhgalteriya/statyi/dobavit` → `addCategory` (admin)
- `POST /sovet/buhgalteriya/statyi/{id}/pereimenovat` → `renameCategory` (admin)
- `POST /sovet/buhgalteriya/statyi/{id}/arhiv` → `toggleCategory` (admin)

Жители:
- `GET  /poselenie/byudzhet` → `BudgetController::index`

Выбранный месяц (для разбивки и ленты операций) — через query `?mesyac=2026-08`, дефолт —
последний месяц с данными. Помесячная таблица показывает все месяцы всегда (без фильтра лет).

### Навигация

- `templates/council/layout.php` — пункт «Бухгалтерия» → `/sovet/buhgalteriya`.
- `templates/council/home.php` — плейсхолдер-строка «Бухгалтерия» ведёт на `/sovet/buhgalteriya`
  (заменяет `PLACEHOLDER-*` Google-Docs-ссылку из `CouncilData.php`).
- `templates/layout.php` (шапка жителей) — пункт «Бюджет Общего дома» → `/poselenie/byudzhet`.
- `templates/cabinet/index.php` — плитка/ссылка «Бюджет Общего дома».
- В `src/data/nav.js` (публичное меню сайта) — **не добавляется** (внутренний раздел).

## Обработка ошибок и краевые случаи

- Пустая сумма / некорректная / отрицательная → Flash-ошибка, возврат на форму.
- Статья не выбрана или не совпадает по типу с операцией → Flash-ошибка.
- Архивированная статья: не предлагается при вводе новой операции, но исторические операции
  по ней остаются в отчёте (имя статьи берётся по `category_id`).
- Попытка операции со справочником не-админом → 403.
- Загрузка чека: ошибки `Upload::saveImage` (не картинка / >5 МБ) → Flash-ошибка, операция
  не создаётся. Отсутствие файла — не ошибка (чек опционален).
- Нет данных вообще / выбран месяц без операций → отчёт показывает пустое состояние, а не падение.
- Гость на `/poselenie/byudzhet` → редирект на вход жителей; гость на `/sovet/buhgalteriya`
  → редирект на вход совета.
- 152-ФЗ: в отчёт и в чеки не попадают персональные данные жителей (только суммы/статьи/
  описания операций и фото чеков продавцов). Имя автора операции — член совета, внутренний.

## Тестирование

phpunit (SQLite in-memory, `php8.3`), по образцу существующих тестов раздела:

- `CouncilCategoryRepository`: create/list по типу, архивация занятой статьи (не удаляется),
  `onlyActive`-фильтр.
- `CouncilLedgerRepository`: create/find/update/delete; агрегации `sumsByMonth`
  (собрано/потрачено помесячно), `expenseByCategory`, `monthsWithData`; удаление операции
  чистит её изображения.
- `LedgerReport`: помесячная таблица с корректным знаком остатка (в т.ч. отрицательным),
  итог за всё время, проценты в разбивке, дефолт-месяц (последний с данными).
- Гарды (через контроллеры или юнит на проверку роли): житель/гость не правят; не-админ не
  трогает справочник.

Верификация: `php8.3 vendor/bin/phpunit` (зелёный набор), затем ручная проверка на проде.

## Деплой

1. Накатить `residents/config/council-ledger-schema.sql` в БД `skazkray_residents` вручную
   (`mysql skazkray_residents < ...`), включая INSERT стартовых статей.
2. Доставить код (tar+scp в `/var/www/skaz-residents` или `deploy.sh`), `php8.3
   /root/composer.phar dump-autoload --optimize --no-dev`.
3. `systemctl reload php8.3-fpm` (opcache — иначе 500 на новых классах).
4. nginx уже маршрутизирует `/sovet` и `/poselenie` — правок конфига не требуется.
5. Проверить: вход совета → внесение операции с чеком; вход жителя → отчёт read-only;
   гость → редиректы; статика/чеки отдаются.

Ветка: `feature/council-budget-ledger` от `main`, мерж после зелёных тестов и ручной проверки.
Пункты меню жителей (`layout.php`/`cabinet`) не требуют пересборки Astro; пункт совета —
тоже в PHP-layout. `nav.js` не трогаем.

## Явно вне объёма (YAGNI)

- Экспорт в PDF/Excel, печатные формы.
- Бюджет-план (план vs факт), лимиты по статьям.
- Интерактивные графики/JS, кольцевые диаграммы.
- Мультивалютность, НДС, проводки двойной записи.
- Учёт задолженностей по взносам по конкретным семьям (отчёт агрегированный, без пофамильных
  данных — 152-ФЗ).
