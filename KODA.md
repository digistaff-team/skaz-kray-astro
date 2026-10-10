# KODA.md

Этот файл служит инструктивным контекстом для ИИ-агентов, работающих с репозиторием.

## Обзор проекта

В одном репозитории живут **два независимых проекта с разными тулчейнами** — не путать их команды и зависимости.

1. **Корень репозитория** — статический сайт `skaz-kray.ru` («Сказочный Край», поселение родовых поместий) на **Astro 7**. Мигрирован с WordPress, собирается в `dist/` и отдаётся nginx как обычная статика.
2. **`residents/`** — самостоятельное **PHP 8.3** приложение (namespace `SkazResidents\`), «раздел для жителей». Свои зависимости (composer), свои тесты (phpunit), свой деплой. Обслуживается на том же домене под путями `/poselenie/…` и `/sovet/…` — nginx проксирует их на PHP-FPM.

## Технологии

### Astro-сайт (корень)

| Что | Значение |
|---|---|
| Фреймворк | Astro `^7.3.5` (Node ≥ 22.12 локально и на сервере) |
| Тип сборки | статическая (`output: static` по умолчанию) |
| Интеграции | `@astrojs/sitemap`, `@astrojs/rss` |
| Шрифты | `@fontsource/pt-sans`, `@fontsource/pt-serif` |
| Контент | коллекции `pages` и `posts` (Content Layer, `glob`-загрузчики) |
| CMS | Decap CMS (две точки входа в `public/`) |
| Dev-зависимости | `gray-matter`, `@wordpress/autop`, `sharp` — нужны только скриптам в `scripts/` |

### Раздел жителей (`residents/`)

| Что | Значение |
|---|---|
| Язык / версия | PHP ≥ 8.3, `declare(strict_types=1)` |
| Фреймворк | отсутствует — собственный MVC (фронт-контроллер + `Router` на regex-маршрутах) |
| Автозагрузка | PSR-4: `SkazResidents\` → `src/`, `SkazResidents\Tests\` → `tests/` |
| Тесты | PHPUnit `^11` (dev-зависимость) |
| Прод-БД | MySQL, `utf8mb4` / `utf8mb4_unicode_ci` |
| Тестовая БД | SQLite in-memory (`tests/schema.sqlite.sql`) |
| Зависимости | только PHP, внешних пакетов в `require` нет |

## Структура каталогов

```
├── astro.config.mjs        # site, trailingSlash, format, фильтр sitemap
├── package.json            # только dev/build/preview, сторонних npm-скриптов нет
├── src/                    # Astro-часть
│   ├── content.config.ts   # схемы коллекций pages / posts
│   ├── content/{pages,posts}/   # markdown-контент
│   ├── pages/              # [...slug].astro, index, kontakty, 404, feed.xml.ts
│   ├── components/         # Header, Footer, PostCard, Gallery, Slider, TelegramMiniApp…
│   ├── layouts/Base.astro  # SEO/OG, inline-скрипт скрытия битых картинок
│   ├── data/               # categories.js, nav.js, home.js
│   ├── lib/utils.js        # ruDate, postSlug, excerptFrom, firstImage
│   └── styles/
├── public/                 # статика: admin/, editor/, fonts/, images/, cms-tg-image.js, robots.txt
├── scripts/                # node-утилиты (см. «Полезные команды»)
├── residents/              # PHP-приложение (см. ниже)
├── server/tg-media/        # серверная часть хостинга картинок в Telegram
├── docs/                   # документация и дизайн-спеки
├── dist/                   # выход статической сборки (не править руками)
└── .worktrees/moi-dela/    # git worktree отдельной ветки
```

### Устройство `residents/`

```
residents/
├── public/index.php        # фронт-контроллер: здесь регистрируются ВСЕ маршруты
├── public/assets/          # residents.css, site-mirror.css, fonts/
├── config/                 # schema*.sql, *-migration.sql, migrations.txt,
│                           # config.example.php, .env.example
├── src/
│   ├── bootstrap.php       # автозагрузка + инициализация
│   ├── Router.php, View.php, Database.php, Config.php, Env.php
│   ├── Auth.php, CouncilAuth.php, Csrf.php, Flash.php, SessionGuard.php,
│   │   LoginThrottle.php, Validator.php, Upload.php, Mailer.php
│   ├── TelegramBot.php, TelegramMedia.php, TelegramSubscription.php, TelegramWebApp.php
│   ├── MaxBot.php, MaxSubscription.php, MaxWebApp.php
│   ├── CouncilData.php, CouncilLedger*, CouncilDutyRotation.php
│   ├── HouseholdName.php, Migrations.php, Sections.php, helpers.php, timezone.php
│   ├── Controller/         # ~27 контроллеров + подкаталог Council/
│   ├── Repository/         # слой доступа к данным
│   ├── Service/            # бизнес-логика (WaterLevel, DeliveryPolicy…)
│   └── templates/          # partials/, public/ (site-mirror), обычные PHP-шаблоны
├── bin/                    # CLI: migrate.php, *-notify.php, *-seed.php, *-admin.php
├── tests/                  # PHPUnit, bootstrap.php + schema.sqlite.sql
└── deploy/                 # deploy.sh, backup-*.sh, README.md, nginx-*.conf.example
```

## Сборка и запуск

### Astro-сайт (в корне)

```bash
npm install          # установка зависимостей
npm run dev          # локальный дев-сервер
npm run build        # статическая сборка в dist/
npm run preview      # предпросмотр собранного
npm run check        # astro check — типизация шаблонов (smoke-тест)
```

Smoke-тест Astro-части: `npm run check` + `npm run build` — обе команды гоняются в CI (`.github/workflows/ci.yml`) на каждый push/PR; там же параллельно phpunit раздела `residents/`. Локально перед пушем достаточно `npm run build`.

### Скрипты-утилиты (запускать напрямую через node, не через npm)

```bash
node scripts/gen-decap-config.mjs    # перегенерировать public/admin/config.yml
node scripts/gen-editor-config.mjs   # перегенерировать public/editor/config.yml
node scripts/convert-content.mjs     # импорт/конвертация WP-контента в src/content
node scripts/optimize-images.mjs     # ужатие public/images/ на месте (sharp)
node scripts/article-collections.mjs # сборка описания коллекций статей
node scripts/novosti-collection.mjs  # описание коллекции «Новости»
```

### Раздел жителей

Локально PHP нет — composer, phpunit и миграции выполняются **на сервере `abconsult`**.

```bash
# Установка зависимостей и запуск тестов (рабочее дерево, не только коммиты)
ssh abconsult 'rm -rf /root/res-test && mkdir -p /root/res-test'
tar -C residents -cf - --exclude=vendor --exclude=.phpunit.cache \
  --exclude=public/uploads --exclude=node_modules --exclude=config/config.php . \
  | ssh abconsult 'tar -x -C /root/res-test'
ssh abconsult 'cd /root/res-test && ([ -f vendor/bin/phpunit ] || php8.3 /root/composer.phar install --no-interaction -q) && php8.3 vendor/bin/phpunit'
ssh abconsult 'rm -rf /root/res-test'

# Отдельный тест
ssh abconsult 'cd /root/res-test && php8.3 vendor/bin/phpunit --filter <ИмяТеста>'

# Деплой кода (работает и из Git Bash на Windows, rsync не нужен)
bash residents/deploy/deploy.sh              # выкатить
bash residents/deploy/deploy.sh --dry-run    # посмотреть, что уедет/удалится
bash residents/deploy/deploy.sh --rollback   # вернуть последний снимок
MIGRATE=1 bash residents/deploy/deploy.sh    # сначала накатить миграции, потом код

# Миграции вручную на сервере
cd /var/www/skaz-residents
php8.3 bin/migrate.php status
php8.3 bin/migrate.php up
php8.3 bin/migrate.php baseline <последний-файл.sql>
```

Деплой сам гоняет phpunit (красные тесты останавливают выкладку), проверяет миграции, снимает резервную копию в `/root/skaz-residents-releases/` (5 последних), пересобирает автозагрузчик и возвращает владельцу `www-data:www-data`. Файлы `config/config.php`, `config/.env`, `public/uploads/`, `vendor/`, `tests/` не перезаписываются.

## Правила разработки

### Общие

- Язык интерфейса, контента, комментариев и сообщений — **русский**. В коде встречаются русские комментарии, объясняющие «зачем»; слаги маршрутов — транслит (`/poselenie/vhod`, `/sovet/zadachi`).
- Перед правкой нетривиальных решений читайте `docs/superpowers/specs/` и `docs/superpowers/plans/` — там первоисточник контекста по фичам (логин редактора, раздел жителей, бюджет совета, PWA).
- Не откатывайте чужие изменения без явного запроса. Не пушьте без указания.

### Astro-сайт

- **Не менять** `format: 'directory'`, `trailingSlash: 'always'` и `site` в `astro.config.mjs` — они сохраняют WP-style URL (`/pravila/` → `pravila/index.html`), старые ссылки уже расшарены.
- **Слаг не менять.** `postSlug()` в `src/lib/utils.js` возвращает уже ASCII-слаги без изменений: URL постов 2013–2016 годов зафиксированы, их трансформация ломает внешние ссылки и SEO.
- **Роутинг** почти целиком генерирует `src/pages/[...slug].astro` через `getStaticPaths`. При добавлении типа страниц правьте его, а не создавайте отдельные маршруты.
- **SEO/OG** только в `src/layouts/Base.astro`: `og:image` всегда абсолютный, превью = обложка → первое фото тела → логотип.
- **Даты** в схемах контента коэрсятся через `dateAsString` в `"YYYY-MM-DD HH:mm:ss"` — обход особенности datetime-виджета Decap, пишущего дату без кавычек. Не упрощать до `z.coerce.date()`.
- **CMS-конфиги генерируемые:** `public/admin/config.yml` и `public/editor/config.yml` править нельзя — только генераторы в `scripts/`, затем перегенерировать и закоммитить. При добавлении рубрики: `src/data/categories.js` → регенерация обоих конфигов.
- **Публичные страницы** `/dnevniki-pomestiy/` и `/yarmarka/` рендерит PHP-приложение через `templates/public/layout.php` и `site-mirror.css` (копия собранного CSS сайта). Меню там захардкожено в `site_header.php` / `site_footer.php` и **синхронизируется вручную** при правке `src/data/nav.js`.
- Комментарии в `scripts/` и конфиге sitemap — ценный контекст, не удалять.

### Раздел жителей

- **Слои не смешивать:** `Controller/` — только HTTP и валидация входа, `Repository/` — SQL, `Service/` — бизнес-логика, `templates/` — вывод через `View.php`.
- **Все маршруты** регистрируются в `residents/public/index.php` (348 строк, единый список). Новый контроллер → импорт + регистрация там же.
- **Гейты доступа** — существующие middlewares-маркеры `RequiresHousehold.php`, `RequiresSection.php`; авторизация через `Auth` (жители), `CouncilAuth` (совет). Роли в `families.role`: `resident` | `editor` | `admin`, где `admin` — надмножество `editor` (`Auth::isEditor()` истинно и для админа); управление разделами только у `admin` (`Auth::requireAdmin`).
- **Подсистема «Совет»** (`Controller/Council/`, таблица `council_members`) обособлена: своя авторизация, своя БД-сущность, email независим от `families`. Не подмешивать её логику в общие контроллеры.
- **CSRF обязателен** на всех POST (`Csrf::guard()`). Учитывайте: при превышении `post_max_size` PHP выбрасывает тело вместе с `_csrf` — лимиты в `php.ini` (см. `deploy/README.md`, п. 4a).
- **Миграции:** новый файл `config/<имя>.sql` + строка **в конец** `config/migrations.txt`. Переставлять и переименовывать применённые нельзя. `MigrationsTest` следит, чтобы каждый `config/*.sql` был в списке. Миграцию писать идемпотентно (`IF NOT EXISTS`) — DDL в MySQL не откатывается.
- **Новые таблицы/колонки дублировать в `tests/schema.sqlite.sql`** — иначе тесты не пройдут.
- **Секреты** — в `config/config.php` (вне git, создаётся из `config.example.php`) и `config/.env`. Токены ботов читаются через `getenv()`. Никогда не коммитить `config.php`, `.env`, содержимое `public/uploads/`.
- **Хелперы и конвенции PHP:** `declare(strict_types=1)` в каждом файле, типы в сигнатурах, PDO-запросы с плейсхолдерами.
- **OPcache** перезагружать не нужно: `opcache.validate_timestamps=On`, байткод перечитывается по mtime.

### Тестирование

- Тесты residentes пишутся по образцу существующих в `residents/tests/` (SQLite in-memory, `tests/bootstrap.php`).
- Названия — `<Тема>Test.php`, класс — `SkazResidents\Tests\<Тема>Test`.
- Покрывать в первую очередь: репозитории (SQL-поведение), сервисы (бизнес-правила), защиту от захвата аккаунта, миграции, обработку входных данных контроллеров.
- Для Astro-части автотестов нет — smoke-тест: `npm run check` (типизация шаблонов) + `npm run build`; ручной просмотр — `npm run preview`. Обе команды гоняются в CI.
- **CI** — `.github/workflows/ci.yml`: на каждый push/PR параллельно идут два джоба: `residents: phpunit` (PHP 8.3, composer install, SQLite in-memory — без секретов и доступа к серверу) и `astro: check + build` (Node 22, `npm ci`). Локально PHP нет, так что CI — самый быстрый способ поймать регрессию до выкладки на `abconsult`.

## Ключевые файлы

| Путь | Назначение |
|---|---|
| `CLAUDE.md` | Сжатая инструкция для агентов: команды и подводные камни |
| `.github/workflows/ci.yml` | CI: phpunit (residents) + `astro check`/`astro build` на каждый push/PR |
| `astro.config.mjs` | Домен, формат URL, фильтр sitemap |
| `src/content.config.ts` | Схемы коллекций `pages` / `posts` |
| `src/pages/[...slug].astro` | Генератор всех страниц, постов, рубрик и redirect-заглушек |
| `src/layouts/Base.astro` | SEO/OG, скрытие битых картинок, Telegram Mini App |
| `src/data/categories.js` | Зеркало WP-таксономии (id/slug/parent) — источник для CMS-конфигов |
| `src/data/nav.js` | Меню шапки и подвала (URL в стиле WP) |
| `src/lib/utils.js` | `ruDate`, `postSlug`, `excerptFrom`, `firstImage` |
| `residents/public/index.php` | Фронт-контроллер и реестр всех маршрутов |
| `residents/config/migrations.txt` | Порядок наката SQL-миграций |
| `residents/config/config.example.php` | Шаблон конфига с секретами (SMTP, Telegram, MAX, uploads) |
| `residents/deploy/README.md` | Пошаговая установка и эксплуатация на сервере |
| `residents/deploy/deploy.sh` | Деплой: тесты → миграции → снимок → выкладка → chown |
| `residents/tests/schema.sqlite.sql` | Схема БД для тестов, обязана соответствовать MySQL |
| `docs/superpowers/{plans,specs}/` | Планы и дизайн-спеки крупных фич |
| `docs/kak-obnovit-prevyu-ssylki.md`, `docs/kak-snyat-kadry-dlya-rolika.md` | Инструкции для редактора |

## Интеграции и внешние сервисы

- **Telegram**: бот `@SkazKray_bot` — Mini App для входа жителей (`/poselenie/tg`) и совета (`/sovet`), гейт по членству в группе, webhook с `X-Telegram-Bot-Api-Secret-Token`, оповещения о задачах и уровне воды; приватный канал «Skaz-Kray Media» — хостинг фото через `/tg-media/<file_id>.jpg`.
- **MAX**: мини-приложение по адресу `https://skaz-kray.ru/max` (проверяется подпись `initData`), гейт — членство в группе жителей MAX.
- **Decap CMS**: `public/admin/` (OAuth через GitHub) и `public/editor/` (вход по паролю на сервере, без GitHub-аккаунта).
- **Google Docs** — ссылки на документы совета (плейсхолдеры `PLACEHOLDER-*` в `src/CouncilData.php`).
- **Внешний источник** `shebsh-water-level.vercel.app` — данные об уровне воды в р. Шебш (запросы идут с нашего сервера, история своя).
- **nginx** (`deploy/nginx-residents.conf.example`): статика + прокси `/poselenie/`, `/sovet/`, `/max`, `/oauth/`, `/editor-auth/` на PHP-FPM; `client_max_body_size 200M`.
- **Бэкапы**: cron 02:25 — дамп БД и файлы вне git в `/root/backups/` (14 наборов), 04:00 — зеркало на Google Drive (`rclone sync`), 04:30 — история дампов всех БД за 30 дней.

## Глоссарий

| Термин | Значение |
|---|---|
| Поместье / хозяйство | Участок и семья-владелец, сущность `households` / `families` |
| Совет (Попечительский совет) | Отдельная подсистема `/sovet/` со своими аккаунтами |
| Хозяйство (`household`) | Единица доступа жителя; совладельцы добавляются заявкой |
| Ledger | Книга расходов/бюджет совета |
| Agenda | Повестка заседания совета |
| Ярмарка | Публичная страница `/yarmarka/` (рендерится PHP) + маркетплейс в приложении |
| `abconsult` | Продакшн-сервер: `root@31.128.43.151`, там же статика сайта |
