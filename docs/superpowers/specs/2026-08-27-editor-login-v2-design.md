# Вход в Decap CMS для редактора без GitHub-аккаунта (v2 — self-hosted логин/пароль)

## Предыстория

Первая попытка (Netlify Identity + Git Gateway, см. `skaz_kray_editor_login_netlify_failed` в памяти сессии) полностью откачена: Netlify отдавал `401 "Login Redirect"` анонимным посетителям, причину не нашли, пользователь счёл диагностику слишком сложной. Эта спека описывает другой подход — без внешних сервисов вообще.

## Решение

Второй, независимый вход `/editor/` — повторяет уже работающую и проверенную схему `/admin/` (self-hosted PHP-страница + `postMessage`-рукопожатие, `backend: github` в Decap CMS), но вместо редиректа на GitHub OAuth сразу показывает форму логин/пароль на нашем сервере. После верного пароля страница отдаёт тот же handshake, что и `oauth/callback.php` для админа, но с одним общим GitHub-токеном (fine-grained Personal Access Token, права только на этот репозиторий, только `Contents: Read and write`) вместо персонального OAuth-токена.

### Почему не git-gateway/Netlify (см. первую попытку) и не собственный GoTrue-сервер

`backend: github` в Decap CMS не заботится о том, откуда взялся токен — только о корректном формате popup-сообщения (`authorization:github:success:{token, provider}`). Значит можно обойтись без email, без JWT-сервиса (GoTrue), без внешних сервисов вообще — просто форма с паролем на PHP, тот же паттерн, что уже проверен на `/admin/`.

### Компоненты

1. **`public/editor/index.html`** — минимальная страница Decap CMS (как `public/admin/index.html`), без Identity-виджета (не нужен — используется `github` backend, не `git-gateway`).
2. **`public/editor/config.yml`** — генерируется `scripts/gen-editor-config.mjs`:
   - `backend: { name: github, repo: digistaff-team/skaz-kray-astro, branch: main, base_url: https://skaz-kray.ru, auth_endpoint: editor-auth/login }`
   - единственная коллекция — «Новости» (схема из общего `scripts/novosti-collection.mjs`, воссоздать — была удалена при откате v1)
3. **`scripts/novosti-collection.mjs`** — воссоздать в исходном виде (общая YAML-схема коллекции «Новости», использовалась и раньше `/admin/`-генератором).
4. **Сервер, вне git** (аналог `oauth/` для админа): `/var/www/new.skaz-kray.ru/html/editor-auth/`
   - `login.php` — GET отдаёт HTML-форму (одно поле — пароль, логин не нужен, редактор один). POST проверяет пароль через `password_verify()` против хеша из `config.php`; при неверном пароле — небольшая задержка (`sleep(1)`) и сообщение об ошибке; при верном — та же handshake-страница, что в `oauth/callback.php` (`window.opener.postMessage('authorization:github:success:' + JSON.stringify({token, provider:'github'}), origin)`), с фиксированным PAT из `config.php`.
   - `config.php` — `password_hash` (bcrypt, `password_hash()`) и PAT. Права `640`, никогда не коммитится (репозиторий публичный).
5. **nginx** (`skaz-kray_ru_astro`) — location-блок для `/editor-auth/login`, аналогичный уже работающему для `/oauth/auth`:
   ```
   location = /editor-auth/login { rewrite ^ /editor-auth/login.php last; }
   ```
   (используется тот же существующий `location ~ ^/oauth/.*\.php$`-паттерн fastcgi, но для `/editor-auth/` — новый аналогичный блок).
6. **Автодеплой** (`skaz-kray-autodeploy.sh`) — добавить `--exclude='editor-auth/'` в rsync (та же причина, что и `--exclude='oauth/'`: секреты на сервере, автодеплой не должен их сносить).

### Что не нужно (в отличие от v1)

Никакого Netlify: ни аккаунта, ни Identity, ни Git Gateway, ни `public/_redirects`. Полностью убирает класс проблем из первой попытки.

### Границы ответственности и безопасность

- Пароль — случайный, сгенерирован при реализации, высокой энтропии (≥20 символов) — практическая невозможность подбора компенсирует отсутствие полноценного rate-limiting; `sleep(1)` на неверный пароль — дополнительная, не единственная защита.
- PAT технически даёт запись во весь репозиторий (GitHub не поддерживает scoping PAT на одну папку/коллекцию) — как и в v1, это программная (UI-уровня), а не жёсткая (git-уровня) граница доступа. Приемлемо для одного доверенного редактора.
- `config.php` (пароль-хеш + PAT) — только на сервере, права `640`, исключён из автодеплоя. Аналогичная модель уже проверена на `oauth/config.php` для `/admin/`.

## Проверка (после реализации)

1. `npm run build` включает `dist/editor/index.html` и `dist/editor/config.yml`.
2. После деплоя `https://skaz-kray.ru/editor/` открывается, кнопка входа открывает popup с формой пароля (не GitHub, не Netlify).
3. Неверный пароль — ошибка в форме. Верный — popup закрывается, Decap CMS открывается с одной коллекцией «Новости».
4. Публикация тестовой новости реально уходит в GitHub и появляется на сайте (~10 мин через автодеплой).
