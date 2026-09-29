# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Дополняет корневой `CLAUDE.md` (общее описание, тесты на сервере) — здесь детали раздела жителей.

## Прод и деплой

Код выкатывается на сервер `abconsult` (root@31.128.43.151) в `/var/www/skaz-residents` — это не git-копия. `deploy/deploy.sh` везёт **файлы с диска** (не из git — незакоммиченные правки тоже уедут) tar-архивом через ssh и удаляет на сервере всё, чего нет здесь. Правки прямо на сервере сразу живые (OPcache `validate_timestamps=On`) и стираются следующим деплоем. Пуш в `main` раздел жителей **не** выкатывает (автодеплоится только Astro-сайт); Decap CMS коммитит в `main` — перед работой `git pull`.

```bash
bash residents/deploy/deploy.sh              # phpunit на сервере → проверка миграций → снимок → выкладка → composer → chown
bash residents/deploy/deploy.sh --dry-run    # что уедет / что удалится
bash residents/deploy/deploy.sh --rollback   # вернуть код из последнего снимка (БД НЕ откатывается)
MIGRATE=1 bash residents/deploy/deploy.sh    # сначала накатить ждущие миграции (без этого деплой остановится)
# SKIP_TESTS=1, FORCE_DELETE=1 — аварийные флаги, см. deploy/README.md
```
На сервере не трогать `config/config.php`, `config/.env`, `public/uploads/`, `var/sessions/` (сессии вошедших жителей); вызывать `php8.3` (просто `php` там — 8.5).

## Миграции

```bash
php8.3 bin/migrate.php status     # на сервере от root; код выхода 2 = есть ждущие
php8.3 bin/migrate.php up
php8.3 bin/migrate.php baseline <файл.sql>   # отметить применёнными, не выполняя
```
Новая миграция = `config/<имя>.sql` + строка в **конец** `config/migrations.txt` (применённые не переставлять и не переименовывать; `MigrationsTest` проверяет список). Писать так, чтобы повтор был безопасен (`IF NOT EXISTS`): DDL в MySQL не транзакционен, упавший файл выполнится заново целиком.

Cron на сервере вызывает `bin/council-meeting-notify.php`, `bin/council-meeting-advance.php`, `bin/water-level-snapshot.php`. Остальные `bin/*.php` — разовые админские CLI, использование — в шапке файла.

## Архитектура

**Путь запроса:** nginx отправляет `/poselenie*`, `/sovet*`, `/max*`, `/dnevniki-pomestiy*`, `/yarmarka*` в `public/index.php` (`deploy/nginx-residents.conf.example`). `src/bootstrap.php`: `config/.env` (`Env`) → `config/config.php` (`Config::get('a.b')`) → московское время (`src/timezone.php`) → 30-дневная сессия в `var/sessions` → PDO → `SessionGuard` (выкидывает заблокированных/удалённых). Все маршруты — в `public/index.php`; `Router` берёт первое совпадение, поэтому конкретные пути (`/novyy`, `/moi`) объявлять раньше `/{id}`.

**Слои:** контроллер сам проверяет доступ и зовёт `Csrf::guard()` на POST → репозиторий (подготовленный SQL, статическое `Database::pdo()`; тесты подменяют через `Database::set()`) → `View::render('каталог/шаблон', $data, $title, $layout)`, экранирование `View::e()`, хелперы шаблонов — `src/helpers.php`.

**Три независимых системы входа:**
- Жители (`/poselenie/*`): `Auth`, таблица `families`, роли `resident` | `editor` | `admin` (`admin` ⊇ `editor`; вкл/выкл разделов — только админ). Вход по паролю, из Telegram Mini App (`TgAuthController`, `TelegramWebApp::verify` + гейт членства в группе) или из мини-приложения MAX (`/max`, `MaxAuthController`, `MaxWebApp`). Разделы, где владелец — поместье, защищает трейт `RequiresHousehold`; отключаемые — `RequiresSection` с ключами из `Sections::LIST` (состояние в `app_sections`, выключенный раздел редиректит на `/poselenie/app`).
- Совет (`/sovet/*`): `CouncilAuth` — свои ключи сессии и таблица `council_members`; статический контент — `src/CouncilData.php`; webhook бота — `/sovet/tg/webhook`.
- Публичные страницы (`/dnevniki-pomestiy`, `/yarmarka`): `PublicController` + `templates/public/layout.php` + `public/assets/site-mirror.css` (копия CSS Astro-сайта). Меню шапки/подвала захардкожено — синхронизировать вручную с `src/data/nav.js` сайта (`deploy/README.md` §10).

**Работа после ответа:** `Service/AfterResponse::run()` закрывает сессию и зовёт `fastcgi_finish_request()`, затем шлёт сообщения через `TelegramBot` / `MaxBot` (обёртка `Service/BotNotify`) или грузит фото в медиаканал Telegram (`TelegramMedia`). Вызывать **только после** `Flash::set()` и заголовка `Location` — дальше flash теряется, ошибки только в `error_log`.

**Загрузки:** `Upload::saveImage` (5 МБ, защита по числу пикселей из-за памяти GD); фото сначала ужимаются на телефоне (`templates/partials/photo-shrink.php`). Хранятся полиморфно в `images` (`owner_type` = `tool`, `book`, …).

**Время:** PHP и сессия MySQL — UTC+3 (Москва). Исключение — уровень воды: почасовые замеры в UTC, перевод явный (`WaterLevel`, `WaterLevelController`, `bin/water-level-snapshot.php`).

Эксплуатационный справочник — `deploy/README.md` (настройка разделов, пороги оповещения о воде, бэкапы, лимиты загрузки PHP-FPM).
