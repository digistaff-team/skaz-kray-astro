# residents-v2 — README

Раздел жителей «Сказочный Край» на Node.js — замена PHP-приложения в `residents/` (легаси, прод-сервер утрачен). Express 4 + TypeScript, БД — Supabase (PostgreSQL) через Prisma. Деплой — Vercel serverless.

## Архитектура

- `api/[...path].ts` — catch-all точка входа Vercel: ловит все `/api/*` и прокидывает в Express-приложение. Vercel забирает **только default-экспорт**, поэтому `src/server.ts` экспортирует сам app (`export default app`), а не `listen()`.
- `src/server.ts` — middleware (helmet, cors по `BASE_URL`, json 200 МБ, `redactSecrets` — вырезает `password_hash` и секреты из ответов/логов) и монтирование `src/routes/*` под `/api/*`. Маршруты — 1:1 с контроллерами PHP-оригинала.
- `src/middleware/` — `auth.ts` (`requireAuth` / `requireAdmin` / `requireEditor`), `lib/csrf.ts`.
- `prisma/schema.prisma` — схема БД, повторяет MySQL-схему легаси.
- Статика загрузок (`/poselenie/uploads`) раздаётся Express **только локально** (`NODE_ENV !== 'production'`); на Vercel файловой системы нет.

В репозитории два Vercel-проекта: сайт (корневой `vercel.json`, Astro) и этот API (`residents-v2/vercel.json`).

## Запуск локально

```bash
cd residents-v2
cp .env.example .env
# Заполнить .env (см. «Переменные окружения»)

npm install
npx prisma generate
npx prisma db push   # создать схему в Supabase
npm run dev          # tsx watch src/server.ts
```

Проверки перед выкладкой:

```bash
npm run typecheck    # tsc --noEmit — основная проверка, должна проходить
npm run build        # prisma generate && tsc → dist/
```

Юнит-тесты крипто-входа — `npm test` (node:test + tsx, `src/lib/telegram.test.ts`); в CI этот проект не входит. Дымовой прогон вручную: register → 200, неавторизованный запрос → 401, неизвестный путь → 404, `GET /api/health`.

Пока не работает (скрипты объявлены, но не готовы): `npm run lint` (eslint-конфига нет), `npm run db:seed` (`prisma/seed.ts` не написан).

## Деплой

Push в `main` выкатывает оба Vercel-проекта (git-деплои включены). Конфиг API — `residents-v2/vercel.json`:

- `buildCommand`: `prisma generate && tsc`, `installCommand`: `npm ci`;
- функция `api/[...path].ts`: `maxDuration` 30 с, `memory` 1024 МБ;
- CORS-заголовки: origin `https://skaz-kray.ru` с `Credentials`.

Клиент сайта (страница входа `/poselenie/tg/`) зовёт API **same-origin**
(`fetch('/api/tg/login')`): корневой `vercel.json` ревритит `/api/*` на этот
проект (`https://residents-v2.vercel.app/api/$1`), поэтому CORS и кроссайтовые
cookie не участвуют. Запасной путь (если внешние rewrites на Vercel окажутся
недоступны): держаться CORS из этого `vercel.json`, а клиенту обязательно
`credentials:'include'` + заголовок `Authorization: Bearer <token>` — cookie с
`SameSite=Lax` кроссайтово не уйдёт.

Переменные окружения задаются в **Vercel Dashboard** (в репозиторий не попадают). Важная особенность: `JWT_SECRET` читается **при вызове**, а не при импорте модуля — иначе на Vercel он `undefined`.

⚠️ `deploy-v2.sh` и `deploy/nginx-v2.conf.example` — из добсерверной эпохи (systemd/nginx на выделенном сервере, уничтожен хостером). **Не использовать**: после перехода на Vercel serverless они ничего не деплоят.

## Переменные окружения

Полный список — `.env.example`. Основные:

- `DATABASE_URL` — прямое postgres-подключение для Prisma (Supabase → Settings → Database → Connection string);
- `SUPABASE_URL` / `SUPABASE_ANON_KEY` / `SUPABASE_SERVICE_ROLE_KEY` — Supabase REST/Storage;
- `JWT_SECRET`, `JWT_EXPIRES_IN` — токены авторизации;
- `SMTP_*` — почта; `SKAZKRAY_*`, `SKAZKRAY_MAX_*` — токены и chat id ботов;
- `UPLOADS_DIR`, `BASE_URL` (https://skaz-kray.ru — origin для CORS), `SESSION_SECRET`.

## Миграция данных MySQL → PostgreSQL

Схему создаёт `prisma db push`; данные переносятся из дампа MariaDB (`residents_db.sql` в корне репозитория) отдельными скриптами — они **ничего не создают**, только параметризованные INSERT в существующие таблицы, с типами из `information_schema` PG и топосортировкой по реальным FK:

```bash
node scripts/migrate-mysql-to-pg.mjs --dry-run   # разбор + сверка схем, без записи
node scripts/migrate-mysql-to-pg.mjs             # загрузка
node scripts/migrate-mysql-to-pg.mjs --truncate  # сначала очистить PG
node scripts/verify-migration.mjs [--sample]     # поимочные хеши строк, sequence, FK-сироты
```

Строки подключения — из `residents-v2/.env` (`DATABASE_URL`). `verify-migration.mjs` сравнивает хеши каждой строки с дампом независимо от скрипта загрузки: правильного `count(*)` мало, нужна гарантия отсутствия сдвига колонок и mojibake.

## Структура

```
residents-v2/
├── api/
│   └── [...path].ts        # catch-all точка входа Vercel → Express
├── prisma/
│   └── schema.prisma       # Схема БД (Supabase/PostgreSQL)
├── scripts/                # миграция данных MySQL → PG и её проверка
├── src/
│   ├── server.ts           # Express-приложение (default export)
│   ├── lib/                # Supabase, JWT, crypto, mailer, CSRF
│   ├── middleware/         # requireAuth, requireAdmin, requireEditor, redact
│   └── routes/             # Контроллеры (1:1 с PHP-оригиналом)
├── vercel.json             # Деплой API (build, функция, CORS)
└── .env.example
```