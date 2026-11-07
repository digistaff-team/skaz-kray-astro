# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Три проекта в одном репозитории

1. **Корень** — статический сайт `skaz-kray.ru` на **Astro** (мигрирован с WordPress). Собирается в `dist/`, выкладывается на **Vercel** (git-push → автодеплой).
2. **`residents-v2/`** — **Node.js/Express/Prisma + Supabase**, серверлесс-приложение на Vercel. Актуальная версия «раздела для жителей»; активная разработка.
3. **`residents/`** — **PHP 8.3** приложение (namespace `SkazResidents\`), прежняя версия раздела жителей. **Легаси, заморожено**: прод-сервер `abconsult` уничтожен хостером (ноябрь 2026), код нигде не выкладывается. Код, тесты и CI сохранены; инструкции деплоя — исторические.

У проектов **разные тулчейны и команды** — не путать.

## Astro-сайт (корень)

### Команды
```bash
npm run dev        # локальный дев-сервер
npm run build      # статическая сборка в dist/
npm run preview    # предпросмотр собранного
npm run check      # astro check — типизация шаблонов (smoke-тест)
```
Скрипты в `scripts/*.mjs` запускаются вручную через `node scripts/<имя>.mjs` (не через npm).

### Деплой (Vercel)
- Push в `main` → Vercel собирает и выкатывает (`git.deploymentEnabled`). Конфиг — корневой `vercel.json`: `framework: astro`, `buildCommand` = `npm ci --prefer-offline && npm run build`, `outputDirectory: dist`.
- Там же URL-политика: rewrites для `/admin/*` и `/editor/*` (Decap SPA), legacy-редиректы с WP-адресов (`/pravila/` → `/o-nas/pravila/` и т.п.), кэш-заголовки для `/fonts/` и `/images/`, `trailingSlash: true`. Редиректы со старых адресов добавлять сюда.

### Ключевые особенности
- **Static, `format: 'directory'`, `trailingSlash: 'always'`** (`astro.config.mjs`) — чтобы сохранить WP-style URL (`/pravila/` → `pravila/index.html`). Не менять без причины: старые ссылки уже расшарены.
- **Astro 7** (Node ≥ 22.12 — и локально, и в CI/Vercel). **Контент** — коллекции `pages` и `posts`: файлы в `src/content/`, схема и `glob`-загрузчики в `src/content.config.ts` (Content Layer; `entry.id` = бывший `entry.slug`, на нём держатся адреса постов, рендер — `render(entry)`). Даты коэрсятся в строку `"YYYY-MM-DD HH:mm:ss"` (обходит особенность datetime-виджета Decap, который пишет дату без кавычек и YAML парсит её как нативный Date).
- **Роутинг** — почти всё генерирует один `src/pages/[...slug].astro` через `getStaticPaths`:
  - `pages` — по своему `permalink` (может быть вложенным);
  - `posts` — по транслитерированному в латиницу слагу (`postSlug`); если исходный кириллический слаг отличуется — на старом адресе генерится **redirect-заглушка** с OG-тегами (пост мог быть уже расшарен);
  - листинги рубрик `/category/<nested>/`.
  Плюс `index.astro`, `kontakty.astro`, `feed.xml.ts` (RSS).
- **Данные** в `src/data/`: `categories.js` (зеркало WP-таксономии — id/slug/parent), `home.js`, `nav.js`. Хелперы — `src/lib/utils.js`.
- **SEO/OG** централизованы в `src/layouts/Base.astro`: og:image всегда абсолютный, картинка превью = обложка поста → первое фото из тела → логотип. Там же inline-скрипт, **прячущий битые картинки** (в постах 2013–2016 ~сотня мёртвых WP-ссылок) и интеграция Telegram Mini App.

### CMS (Decap)
- Две точки входа: `public/admin/` (backend GitHub OAuth) и `public/editor/` (тот же GitHub-репозиторий, но вход по паролю на сервере, для редактора без GitHub-аккаунта).
- **`config.yml` в обоих — генерируемые**: правьте генераторы `scripts/gen-decap-config.mjs` / `scripts/gen-editor-config.mjs`, затем перегенерируйте и закоммитьте `config.yml` (файлы под git). Общие описания коллекций — `scripts/novosti-collection.mjs` и `scripts/article-collections.mjs` (последний выводит вкладки рубрик из `src/data/categories.js`). При добавлении рубрики правьте `categories.js` и регенерируйте конфиги.
- `public/cms-tg-image.js` — кастомный виджет для картинок, хостящихся в Telegram (подключается из `public/admin/index.html` и `public/editor/index.html`).
- `scripts/convert-content.mjs` — одноразовый импортёр WP-контента (чистит шорткоды/MSO-мусор, локализует ссылки). `scripts/optimize-images.mjs` — ужимает `public/images/` на месте.
- `scripts/announce-post.mjs` — анонс статьи в чат жителей от @SkazKray_bot одной командой `node scripts/announce-post.mjs` (свежая статья по date; конкретная — `--slug <слаг>`; проба — `--dry-run`). Дубли отсекаются по журналу `telegram_messages` (`context=post-announce:<slug>`).

## Раздел жителей v2 (`residents-v2/`) — активная разработка

Express 4 на TypeScript, БД — Supabase (PostgreSQL) через Prisma. Портирует PHP-оригинал (`residents/`) по маршруту: API живёт под `/api/*`, фронт — тот же Astro-сайт.

- **Точка входа** — `src/server.ts`, Express-приложение **экспортируется как default export** (`export default app`). Vercel-обёртка — catch-all `api/[...path].ts`: ловит все `/api/*` и прокидывает в это приложение; Vercel забирает только default-экспорт, поэтому сам app и является функцией `(req, res)`.
- **Маршруты** `src/routes/*` — 1:1 с контроллерами PHP-оригинала (`auth`, `cabinet`, `diary`, `council`, `tg`, `max`, …). Middleware: `middleware/auth.ts` (`requireAuth` / `requireAdmin` / `requireEditor`), `lib/csrf.ts`, `middleware/redact.ts` (замаскировать секреты в логах/ошибках).
- **Схема БД** — `prisma/schema.prisma` (повторяет MySQL-схему легаси со всеми миграциями). Накат — `npx prisma db push` (файловых миграций Prisma нет).
- **Команды** (запускать внутри `residents-v2/`):
```bash
npm run dev         # tsx watch src/server.ts
npm run build       # prisma generate && tsc → dist/
npm run typecheck   # tsc --noEmit — проходит, основная проверка
npm run db:push     # применить схему к Supabase
```
`npm run lint` и `npm run db:seed` объявлены, но пока не работают: eslint-конфига в репо нет (eslint не находит файлы в `src/`), `prisma/seed.ts` не написан.
- **Секреты** — `.env` по образцу `.env.example`: `DATABASE_URL` (Prisma), `SUPABASE_URL` / `SUPABASE_SERVICE_ROLE_KEY`, `JWT_SECRET` (читается **при вызове**, не при импорте — иначе undefined на Vercel), `SMTP_*`, токены ботов, `BASE_URL`.
- **Тестов нет, в CI не входит** — typecheck/build прогонять вручную перед выкладкой. Дымовой прогон (register → 200, 401, 404, health) — локально на `npm run dev`.
- `residents-v2/README.md` — актуальный разбор запуска/деплоя/миграции данных. `deploy-v2.sh` и `deploy/nginx-v2.conf.example` — добсерверная эпоха, не использовать; конфиг деплоя — `residents-v2/vercel.json`.

## Раздел жителей, PHP (`residents/`) — легаси, заморожен

Код больше нигде не деплоится (сервер `abconsult` уничтожен). Сохранён как источник истины для миграции в `residents-v2/` и пока жив в CI. **Не выполнять** `deploy/deploy.sh` и ssh-инструкции из `residents/CLAUDE.md` — они описывают уже несуществующий сервер.

Классический MVC без фреймворка: фронт-контроллер `public/index.php` + собственный `Router` (регэксп-маршруты). Слои: `Controller/`, `Repository/`, `Service/`, шаблоны — обычный PHP через `View.php` (`src/templates/`).

- **Подсистема «Совет»** (`Controller/Council/`) — заметно обособлена: заседания, повестка (agenda), бюджет/книга расходов (ledger), задачи, Telegram-бот (webhook), своя авторизация.
- Прочие разделы: дневники поместий, маркетплейс, прокат инструментов и книг, поездки/попутки, совместные оптовые закупки, справочник жителей, профили хозяйств, PWA/app-режим.
- **БД**: была MySQL (utf8mb4), схемы и миграции в `config/*.sql`, порядок — `config/migrations.txt`, накат — `bin/migrate.php`. Тестовая схема — SQLite (`tests/schema.sqlite.sql`) — новые таблицы/колонки дублировать и туда.
- **Секреты** — `config/config.php` (вне git, из `config.example.php`) и `.env`. CLI-утилиты в `bin/`.

### Тесты PHP
CI (`.github/workflows/ci.yml`) на каждый push/PR: **phpunit** (PHP 8.3, SQLite, без секретов) и smoke-тест Astro-части (`npm run check` + `npm run build`). Локально PHP не установлен, ssh-сервер недоступен — **CI единственный способ прогнать phpunit**. Тесты используют SQLite in-memory по образцу существующих в `residents/tests/`; один тест — `vendor/bin/phpunit --filter <TestName>` (в CI или в любом окружении с PHP 8.3).

## Документация проекта
`docs/superpowers/` — планы (`plans/`) и дизайн-спеки (`specs/`) по крупным фичам (логин редактора v2, раздел жителей, бюджет совета, mobile PWA/app-режим). Это первоисточник контекста по нетривиальным решениям; часть планов писалась до переезда на Vercel/Supabase — учитывать дату. `docs/kak-obnovit-prevyu-ssylki.md` — инструкция для редактора про превью ссылок.
<!-- BEGIN:review-rules -->
## Code review

Процесс по мотивам статьи команды Claude Code ["How the Claude Code team does code review"](https://claude.dev/blog/solving-code-review-at-anthropic/). Детальные правила — в [`REVIEW.md`](./REVIEW.md); здесь — только то, что относится к каждому изменению.

**Верификация вместо «готово».** Не завершай задачу без вывода проверок: минимум typecheck (`npx tsc --noEmit` для TS-проектов) + линтер проекта; для изменений рантайма/сборки — ещё build (`npm run build` или эквивалент). «Проверено» без вывода команды = незавершённая задача. UI-изменения — ручная проверка с перечнем, что именно проверено.

**Ревью диффа свежим взглядом.** Перед финалом перечитай свой diff как «второй ревьюер» (отдельный субагент/сессия без контекста написания): ищи root cause, а не симптом; каждое замечание — с файлом и строкой; замечание = класс бага («тот же паттерн в других местах»), а не одна строка.

**Сходимость ревью.** Максимум 3 раунда: после третьего — остановись и пересмотри подход, а не точечно правь строки. Повторный проход — только по новому diff; уже высказанные замечания не повторяй. Каждое замечание закрывается явно: исправлено либо resolved с причиной (false positive / намеренное поведение).

**Уровень риска задаёт изменение, не автор** — мнение агента не заменяет решение человека:

- **Высокий — всегда смотрит человек:** auth/сессии/гейты доступа, оплата и биллинг, CI/CD и деплой (workflows, Dockerfile, инфраструктурные скрипты), секреты и env, квоты/лимиты платных AI-вызовов, схема БД и миграции, 152-ФЗ/ПДн и всё, что уходит в LLM.
- **Средний — один человек:** архитектура AI-слоя, новые AI-вызовы и промпты, интеграции с мессенджерами/внешними API, гейтинг и роли, контент-миграции в БД.
- **Низкий:** контент, копирайт, UI-правки, аддитивные фичи — достаточно зелёного CI.

**Исправляй в источнике.** Повторяющееся замечание ревью = чинить причину: правило линтера, строка в REVIEW.md/CLAUDE.md или шаг CI — а не очередная точечная правка. Пропущенный баг — вопрос «чего не хватило процессу ревью», а не «кто виноват».
<!-- END:review-rules -->
