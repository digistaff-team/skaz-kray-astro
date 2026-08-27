# Editor Login v2 (self-hosted login/password) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the reverted Netlify-based editor login with a fully self-hosted `/editor/` entry point: Decap CMS `github` backend, popup shows a password form (not GitHub OAuth, not Netlify), successful login hands back one shared fine-grained GitHub PAT via the same `postMessage` handshake already proven on `/admin/`.

**Architecture:** Mirrors `/admin/`'s working self-hosted-OAuth-proxy pattern almost exactly (`oauth/auth.php` + `oauth/callback.php` + `oauth/config.php`, all outside git) — except the "auth" step is a password form instead of a GitHub redirect, and the token handed back is a fixed, pre-generated PAT instead of a per-user OAuth token. No external services (no Netlify, no email, no JWT server).

**Tech Stack:** Decap CMS 3.5.x (`github` backend), PHP 8.3-FPM (same as `/admin/`'s proxy), Node.js generator scripts, Astro static build, nginx.

**Full design/rationale:** `docs/superpowers/specs/2026-08-27-editor-login-v2-design.md`
**Prior attempt (reverted, reasons why):** `docs/superpowers/specs/2026-08-27-editor-login-design.md` was deleted on revert — see session memory `skaz_kray_editor_login_netlify_failed` for what was tried and why it was abandoned (Netlify returned `401 Login Redirect` to anonymous visitors, root cause not found).

**Verification approach:** No test runner in this project (`package.json` only has `dev`/`build`/`preview`). Verification is by running generator scripts/build and inspecting actual output, and — for the server-side PHP — by curling the live endpoints after deploy.

---

### Task 1: Shared «Новости» collection schema

**Files:**
- Create: `scripts/novosti-collection.mjs`
- Modify: `scripts/gen-decap-config.mjs` (add import, replace inlined `novosti` collection block with the shared export)

This is identical in content to a previously-implemented-and-triple-reviewed task from the reverted v1 plan — recreating it verbatim (the code itself was never the problem; only the Netlify auth layer was).

- [ ] **Step 1: Create the shared collection module**

Create `scripts/novosti-collection.mjs`:

```js
// Общее описание коллекции «Новости» для Decap CMS — используется и
// основным конфигом (/admin/), и конфигом редактора (/editor/), чтобы
// поля не расходились при правке.
export const novostiCollectionYaml = `  - name: novosti
    label: "Новости"
    label_singular: "Новость"
    description: "Быстрая заметка в раздел «Новости» на сайте. Заполните заголовок и текст — остальное подставится само."
    folder: "src/content/posts"
    create: true
    slug: "{{slug}}"
    identifier_field: title
    sortable_fields: [date, title]
    fields:
      - { name: title, label: "Заголовок новости", widget: string }
      - { name: date, label: "Дата публикации", widget: datetime, default: "{{now}}", date_format: "YYYY-MM-DD", time_format: "HH:mm:ss", format: "YYYY-MM-DD HH:mm:ss" }
      - { name: body, label: "Текст новости", widget: markdown }
      - { name: categories, widget: hidden, default: ["novosti"] }`;
```

- [ ] **Step 2: Wire it into the existing admin generator**

In `scripts/gen-decap-config.mjs`, add the import right after the existing `fs` import:

```js
import fs from 'node:fs';
import { novostiCollectionYaml } from './novosti-collection.mjs';
const { categories } = await import('../src/data/categories.js');
```

Then replace the inlined `novosti` collection block (the text starting at `  - name: novosti` and ending at `      - { name: categories, widget: hidden, default: ["novosti"] }`, immediately before the blank line and `  - name: posts`) with a single interpolation line:

```
${novostiCollectionYaml}
```

- [ ] **Step 3: Verify the generated output is byte-identical**

Run (from the repo root):
```bash
node scripts/gen-decap-config.mjs
git diff --stat public/admin/config.yml
```
Expected: `node` prints `Wrote public/admin/config.yml with 27 category options` and `git diff --stat` prints **nothing**.

- [ ] **Step 4: Commit**

```bash
git add scripts/novosti-collection.mjs scripts/gen-decap-config.mjs
git commit -m "refactor(cms): вынести схему коллекции «Новости» в общий модуль"
```

---

### Task 2: Editor config generator (github backend, password auth_endpoint)

**Files:**
- Create: `scripts/gen-editor-config.mjs`
- Create (generated): `public/editor/config.yml`

- [ ] **Step 1: Create the generator**

Create `scripts/gen-editor-config.mjs`:

```js
// Generate public/editor/config.yml for Decap CMS — вход для редактора
// без GitHub-аккаунта (github backend, но auth_endpoint — своя форма
// пароля на сервере, не GitHub OAuth). См.
// docs/superpowers/specs/2026-08-27-editor-login-v2-design.md
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { novostiCollectionYaml } from './novosti-collection.mjs';

const yml = `# Decap CMS — вход для редактора (только раздел «Новости»).
# Сгенерировано scripts/gen-editor-config.mjs — правьте генератор, не этот файл.

backend:
  name: github
  repo: digistaff-team/skaz-kray-astro
  branch: main
  base_url: https://skaz-kray.ru
  auth_endpoint: editor-auth/login

locale: ru
site_url: https://skaz-kray.ru
display_url: https://skaz-kray.ru
media_folder: "public/wp-content/uploads/decap"
public_folder: "/wp-content/uploads/decap"

collections:
${novostiCollectionYaml}
`;

// Путь резолвится относительно расположения этого файла, не хардкод
// абсолютного пути (см. известный баг в gen-decap-config.mjs) — работает
// одинаково и из основного checkout, и из git worktree.
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(scriptDir, '..', 'public', 'editor');
fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(path.join(outDir, 'config.yml'), yml);
console.log('Wrote public/editor/config.yml');
```

- [ ] **Step 2: Run it and verify the output**

Run (from the repo root):
```bash
node scripts/gen-editor-config.mjs
cat public/editor/config.yml
```
Expected: prints `Wrote public/editor/config.yml`, and the file contains `backend: { name: github, repo: digistaff-team/skaz-kray-astro, branch: main, base_url: https://skaz-kray.ru, auth_endpoint: editor-auth/login }`, plus a single `novosti` collection (no `posts`/`pages`). No `app_id`/`auth_type: pkce`/Netlify-anything anywhere in the file.

- [ ] **Step 3: Commit**

```bash
git add scripts/gen-editor-config.mjs public/editor/config.yml
git commit -m "feat(cms): генератор конфига редактора (github backend, форма пароля)"
```

---

### Task 3: Editor admin HTML page

**Files:**
- Create: `public/editor/index.html`

- [ ] **Step 1: Create the page**

Create `public/editor/index.html` (same minimal pattern as `public/admin/index.html` — no Identity widget needed, this uses the plain `github` backend):

```html
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Вход для редактора — Сказочный Край</title>
</head>
<body>
  <script src="https://unpkg.com/decap-cms@^3.5.0/dist/decap-cms.js"></script>
</body>
</html>
```

- [ ] **Step 2: Verify**

Run (from the repo root):
```bash
grep -c "decap-cms" public/editor/index.html
```
Expected: `1`

- [ ] **Step 3: Commit**

```bash
git add public/editor/index.html
git commit -m "feat(cms): страница входа редактора /editor/"
```

---

### Task 4: robots.txt + build verification

**Files:**
- Modify: `public/robots.txt`

- [ ] **Step 1: Exclude /editor/ from indexing**

In `public/robots.txt`, add a line right after the existing `Disallow: /admin/`:

```
Disallow: /editor/
```

- [ ] **Step 2: Full build**

Run (from the repo root):
```bash
npm run build
```
Expected: build succeeds, no errors mentioning `editor`.

- [ ] **Step 3: Confirm build output**

Run (from the repo root):
```bash
test -f dist/editor/index.html && test -f dist/editor/config.yml && grep -q "Disallow: /editor/" dist/robots.txt && echo "OK: all present"
```
Expected: `OK: all present`

- [ ] **Step 4: Commit**

```bash
git add public/robots.txt
git commit -m "chore: исключить /editor/ из robots.txt"
```

---

### Task 5: Push and finish branch

**Files:** none

- [ ] **Step 1: Push the feature branch**

```bash
git push -u origin feature/editor-login-v2
```

This plan's tasks end here (client-side code only). Merging into `main` happens via `superpowers:finishing-a-development-branch`. The server-side work (PHP login page, nginx, autodeploy exclusion, generating the real password + PAT) is **NOT** part of this plan — it's manual/interactive follow-up (needs a fine-grained GitHub PAT the user must create and paste in chat, same as the OAuth App before), detailed below.

---

## Manual follow-up (server-side, after this plan's branch is merged)

Unlike the client-side tasks above, this work touches a live production server and a secret (GitHub PAT) that must never be committed — it's done directly (by the coordinating session, with the user's input for the PAT), not as an isolated subagent task.

1. **User creates a fine-grained GitHub PAT**: github.com → Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token. Repository access: only `digistaff-team/skaz-kray-astro`. Permissions: **Contents: Read and write** (nothing else needed). User pastes the token in chat (same pattern as the OAuth App client secret earlier this session).
2. Generate a random high-entropy password for the editor (e.g. `openssl rand -base64 18`).
3. On the server (`ssh abconsult`), create `/var/www/new.skaz-kray.ru/html/editor-auth/`:
   - `config.php` — returns `['password_hash' => password_hash($password, PASSWORD_BCRYPT), 'github_pat' => '<the PAT>']`, permissions `640`, never in git.
   - `login.php` — GET shows a password form; POST verifies via `password_verify()` (on failure: `sleep(1)` + error message; on success: the exact same `postMessage` handshake HTML already proven in `oauth/callback.php`, with the fixed `github_pat` instead of a per-request OAuth token).
4. Add an nginx location block to `skaz-kray_ru_astro` (mirroring the existing `/oauth/` block):
   ```
   location = /editor-auth/login { rewrite ^ /editor-auth/login.php last; }
   location ~ ^/editor-auth/.*\.php$ {
       include snippets/fastcgi-php.conf;
       fastcgi_pass unix:/run/php/php8.3-fpm.sock;
   }
   ```
   `nginx -t` then `systemctl reload nginx`.
5. Add `--exclude='editor-auth/'` to the rsync line in `/usr/local/bin/skaz-kray-autodeploy.sh` (currently `--exclude='wp-content/' --exclude='oauth/'`) — otherwise the next autodeploy cron run deletes `config.php` and the secrets in it.
6. Smoke test: `curl -I https://skaz-kray.ru/editor-auth/login` should return the login form (200), not 404. Then open `https://skaz-kray.ru/editor/` in a browser, click login, confirm the password popup appears and works end-to-end.
7. Give the generated password to the editor through whatever channel you normally use (not part of this plan — outside the scope of what the assistant should transmit).
