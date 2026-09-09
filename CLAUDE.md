# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Два проекта в одном репозитории

1. **Корень** — статический сайт `skaz-kray.ru` на **Astro** (мигрирован с WordPress). Собирается в `dist/`, отдаётся nginx как статика.
2. **`residents/`** — отдельное **PHP 8.3** приложение (namespace `SkazResidents\`), «раздел для жителей» поселения. Свои зависимости (composer), свои тесты (phpunit), свой деплой. Живёт под путями `/poselenie/…` на том же домене (nginx проксирует их на PHP-FPM, остальное — статика Astro).

У проектов **разные тулчейны и команды** — не путать.

## Astro-сайт (корень)

### Команды
```bash
npm run dev        # локальный дев-сервер
npm run build      # статическая сборка в dist/
npm run preview    # предпросмотр собранного
```
Скрипты в `scripts/*.mjs` запускаются вручную через `node scripts/<имя>.mjs` (не через npm).

### Ключевые особенности
- **Static, `format: 'directory'`, `trailingSlash: 'always'`** (`astro.config.mjs`) — чтобы сохранить WP-style URL (`/pravila/` → `pravila/index.html`). Не менять без причины: старые ссылки уже расшарены.
- **Контент** — коллекции `pages` и `posts` в `src/content/`, схема в `src/content/config.ts`. Даты коэрсятся в строку `"YYYY-MM-DD HH:mm:ss"` (обходит особенность datetime-виджета Decap, который пишет дату без кавычек и YAML парсит её как нативный Date).
- **Роутинг** — почти всё генерирует один `src/pages/[...slug].astro` через `getStaticPaths`:
  - `pages` — по своему `permalink` (может быть вложенным);
  - `posts` — по транслитерированному в латиницу слагу (`postSlug`); если исходный кириллический слаг отличается — на старом адресе генерится **redirect-заглушка** с OG-тегами (пост мог быть уже расшарен);
  - листинги рубрик `/category/<nested>/`.
  Плюс `index.astro`, `kontakty.astro`, `feed.xml.ts` (RSS).
- **Данные** в `src/data/`: `categories.js` (зеркало WP-таксономии — id/slug/parent), `home.js`, `nav.js`. Хелперы — `src/lib/utils.js`.
- **SEO/OG** централизованы в `src/layouts/Base.astro`: og:image всегда абсолютный, картинка превью = обложка поста → первое фото из тела → логотип. Там же inline-скрипт, **прячущий битые картинки** (в постах 2013–2016 ~сотня мёртвых WP-ссылок) и интеграция Telegram Mini App.

### CMS (Decap)
- Две точки входа: `public/admin/` (backend GitHub OAuth) и `public/editor/` (тот же GitHub-репозиторий, но вход по паролю на сервере, для редактора без GitHub-аккаунта).
- **`config.yml` в обоих — генерируемые**: правьте генераторы `scripts/gen-decap-config.mjs` / `scripts/gen-editor-config.mjs`, затем перегенерируйте и закоммитьте `config.yml` (файлы под git). Общие описания коллекций — `scripts/novosti-collection.mjs` и `scripts/article-collections.mjs` (последний выводит вкладки рубрик из `src/data/categories.js`). При добавлении рубрики правьте `categories.js` и регенерируйте конфиги.
- `public/admin/cms-tg-image.js` — кастомный виджет для картинок, хостящихся в Telegram.
- `scripts/convert-content.mjs` — одноразовый импортёр WP-контента (чистит шорткоды/MSO-мусор, локализует ссылки). `scripts/optimize-images.mjs` — ужимает `public/images/` на месте.

## Раздел жителей (`residents/`)

Классический MVC без фреймворка: фронт-контроллер `public/index.php` + собственный `Router` (регэксп-маршруты). Слои: `Controller/`, `Repository/`, `Service/`, шаблоны — обычный PHP через `View.php` (`src/templates/`).

- **Подсистема «Совет»** (`Controller/Council/`) — заметно обособлена: заседания, повестка (agenda), бюджет/книга расходов (ledger), задачи, Telegram-бот (webhook), своя авторизация.
- Прочие разделы: дневники поместий, маркетплейс, прокат инструментов и книг, поездки/попутки, справочник жителей, профили хозяйств, PWA/app-режим.
- **БД**: прод — MySQL (utf8mb4), схемы и миграции в `config/*.sql`. Тесты — SQLite (`tests/schema.sqlite.sql`).
- **Секреты** — `config/config.php` (вне git, из `config.example.php`) и `.env`. CLI-утилиты в `bin/`.

### Тесты и деплой
**Локально PHP нет** — phpunit гоняется на сервере `abconsult`:
```bash
git archive HEAD:residents | ssh abconsult 'tar -x -C /root/ledger-test'
ssh abconsult 'cd /root/ledger-test && ([ -f vendor/bin/phpunit ] || php8.3 /root/composer.phar install --no-interaction -q) && php8.3 vendor/bin/phpunit'
```
Отдельный тест — `php8.3 vendor/bin/phpunit --filter <TestName>`. Тесты используют SQLite in-memory по образцу существующих в `residents/tests/`.

Деплой раздела — `residents/deploy/deploy.sh` (rsync на `abconsult`, `config.php`/uploads/vendor не трогает). Инструкция по первичной установке — `residents/deploy/README.md`.

## Документация проекта
`docs/superpowers/` — планы (`plans/`) и дизайн-спеки (`specs/`) по крупным фичам (логин редактора v2, раздел жителей, бюджет совета, mobile PWA/app-режим). Это первоисточник контекста по нетривиальным решениям. `docs/kak-obnovit-prevyu-ssylki.md` — инструкция для редактора про превью ссылок.
