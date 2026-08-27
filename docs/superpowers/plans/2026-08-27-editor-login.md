# Editor Login (Netlify Identity + Git Gateway) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a second, independent Decap CMS entry point at `/editor/` that lets a non-technical editor (email only, no GitHub account) log in and publish only to the «Новости» collection, without touching the existing `/admin/` GitHub OAuth flow.

**Architecture:** `/editor/` is a separate static HTML page + `config.yml` using Decap's `git-gateway` backend, pointed at a Netlify site (created manually by the user) that has Identity + Git Gateway enabled and connected to `digistaff-team/skaz-kray-astro`. The Netlify Identity widget script on the editor page handles both the one-time invite/password-setup flow and subsequent logins; git-gateway itself proxies commits to GitHub using a token Netlify manages, so the editor never needs a GitHub account. The «Новости» collection field schema is extracted into a shared module so `/admin/` and `/editor/` can't drift apart.

**Tech Stack:** Decap CMS 3.5.x (`git-gateway` backend), Netlify Identity widget (CDN script), Node.js generator scripts (existing `scripts/gen-decap-config.mjs` pattern), Astro static build.

**Full design/rationale:** `docs/superpowers/specs/2026-08-27-editor-login-design.md`

**Verification approach:** This project has no test runner (confirmed: `package.json` only has `dev`/`build`/`preview` scripts). Every task is verified by running the generator/build and inspecting the actual output file content or diff, matching how `scripts/gen-decap-config.mjs` is already verified in this repo.

---

### Task 1: Extract shared «Новости» collection schema

**Files:**
- Create: `scripts/novosti-collection.mjs`
- Modify: `scripts/gen-decap-config.mjs:1-19` (add import, replace inlined `novosti` collection block with the shared export)

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

Then replace the inlined `novosti` collection block (the text starting at `  - name: novosti` and ending at `      - { name: categories, widget: hidden, default: ["novosti"] }`, immediately before the blank line and `  - name: posts`) with:

```
${novostiCollectionYaml}
```

(a single template-literal interpolation line, replacing the entire inlined block — the rest of the file, including the `posts` and `pages` collections, is unchanged)

- [ ] **Step 3: Verify the generated output is byte-identical**

Run (from the repo root — main checkout or worktree):
```bash
node scripts/gen-decap-config.mjs
git diff --stat public/admin/config.yml
```
Expected: `node` prints `Wrote public/admin/config.yml with 27 category options` and `git diff --stat` prints **nothing** (empty output — confirms the refactor didn't change the generated file).

- [ ] **Step 4: Commit**

```bash
git add scripts/novosti-collection.mjs scripts/gen-decap-config.mjs
git commit -m "refactor(cms): вынести схему коллекции «Новости» в общий модуль"
```

---

### Task 2: Editor config generator

**Files:**
- Create: `scripts/gen-editor-config.mjs`
- Create (generated, do not hand-edit): `public/editor/config.yml`

- [ ] **Step 1: Create the generator**

Create `scripts/gen-editor-config.mjs`:

```js
// Generate public/editor/config.yml for Decap CMS — вход для редактора
// без GitHub-аккаунта (git-gateway backend + Netlify Identity).
// См. docs/superpowers/specs/2026-08-27-editor-login-design.md
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { novostiCollectionYaml } from './novosti-collection.mjs';

// Поддомен Netlify-сайта, на котором включены Identity + Git Gateway
// (Site settings → Site details → Site name на app.netlify.com).
// Заводится вручную (создание стороннего аккаунта — вне зоны действия
// генератора), см. спеку. После того как сайт создан, подставьте сюда
// реальное значение и перезапустите генератор.
const NETLIFY_SITE = 'REPLACE-ME.netlify.app';

const yml = `# Decap CMS — вход для редактора (только раздел «Новости»).
# Сгенерировано scripts/gen-editor-config.mjs — правьте генератор, не этот файл.

backend:
  name: git-gateway
  branch: main
  identity_url: https://${NETLIFY_SITE}/.netlify/identity
  gateway_url: https://${NETLIFY_SITE}/.netlify/git

locale: ru
site_url: https://skaz-kray.ru
display_url: https://skaz-kray.ru
media_folder: "public/wp-content/uploads/decap"
public_folder: "/wp-content/uploads/decap"

collections:
${novostiCollectionYaml}
`;

// Путь резолвится относительно расположения этого файла (не хардкод
// абсолютного пути) — так генератор корректно работает и из основного
// checkout, и из git worktree. mkdirSync с recursive:true нужен, потому
// что на момент первого запуска этой задачи папки public/editor/ ещё
// не существует (её создаёт Task 3) — writeFileSync сам директорию не создаёт.
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(scriptDir, '..', 'public', 'editor');
fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(path.join(outDir, 'config.yml'), yml);
console.log('Wrote public/editor/config.yml (Netlify site:', NETLIFY_SITE + ')');
```

- [ ] **Step 2: Run it and verify the output**

Run (from the repo root — main checkout or worktree, the script resolves its own path either way):
```bash
node scripts/gen-editor-config.mjs
cat public/editor/config.yml
```
Expected: prints `Wrote public/editor/config.yml (Netlify site: REPLACE-ME.netlify.app)`, and the file contains `backend: { name: git-gateway, ... }` with `identity_url`/`gateway_url` pointing at `REPLACE-ME.netlify.app`, plus a single `novosti` collection (no `posts`/`pages`).

- [ ] **Step 3: Commit**

```bash
git add scripts/gen-editor-config.mjs public/editor/config.yml
git commit -m "feat(cms): генератор конфига редактора (git-gateway, только Новости)"
```

---

### Task 3: Editor admin HTML page

**Files:**
- Create: `public/editor/index.html`

- [ ] **Step 1: Create the page**

Create `public/editor/index.html`:

```html
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Вход для редактора — Сказочный Край</title>
  <!-- Netlify Identity widget — ДО decap-cms.js. Нужен для (1) обработки
       ссылки-приглашения из письма (установка пароля редактором) и
       (2) формы входа логин+пароль при последующих визитах. -->
  <script src="https://identity.netlify.com/v1/netlify-identity-widget.js"></script>
</head>
<body>
  <script src="https://unpkg.com/decap-cms@^3.5.0/dist/decap-cms.js"></script>
  <script>
    if (window.netlifyIdentity) {
      window.netlifyIdentity.on("init", user => {
        if (!user) {
          window.netlifyIdentity.on("login", () => {
            document.location.href = "/editor/";
          });
        }
      });
    }
  </script>
</body>
</html>
```

- [ ] **Step 2: Verify the widget script is present**

Run (from the repo root):
```bash
grep -c "netlify-identity-widget" public/editor/index.html
```
Expected: `1`

- [ ] **Step 3: Commit**

```bash
git add public/editor/index.html
git commit -m "feat(cms): страница входа редактора /editor/"
```

---

### Task 4: Build verification

**Files:** none (verification only)

- [ ] **Step 1: Full build**

Run (from the repo root):
```bash
npm run build
```
Expected: build succeeds (exit code 0), no errors mentioning `editor`.

- [ ] **Step 2: Confirm the editor page made it into the build output**

Run (from the repo root):
```bash
test -f dist/editor/index.html && test -f dist/editor/config.yml && echo "OK: both present"
```
Expected: `OK: both present`

(No commit — `dist/` is gitignored, nothing to stage.)

---

### Task 5: Push feature branch

**Files:** none

- [ ] **Step 1: Push the feature branch (not main)**

```bash
git push -u origin feature/editor-login
```
Expected: `feature/editor-login -> feature/editor-login` push succeeds.

This plan's tasks end here. Merging `feature/editor-login` into `main` happens afterward via `superpowers:finishing-a-development-branch` (per the subagent-driven-development process — final code review, then branch integration), not as a plan task. Only once merged and pushed to `main` will the existing autodeploy cron (runs every 10 minutes on the server) pick it up — no manual server deploy step needed, matching how the `/admin/` OAuth fix shipped earlier.

---

## Manual follow-up (not part of this plan — requires the user's own accounts/actions)

These steps cannot be executed by an agent: they require creating and configuring a third-party (Netlify) account, which is account creation and must be done by the user themselves.

1. **Create a free Netlify account**, then **Add new site → Import an existing project**, connect GitHub, choose `digistaff-team/skaz-kray-astro`, branch `main`. (Netlify will build and host its own copy at `https://<random-name>.netlify.app` — this copy is never linked from DNS and is only used to host the Identity/Git Gateway services; it is not the production site.)
2. In that site: **Project configuration → Identity → Enable Identity**.
3. **Identity → Registration → Invite only** (so nobody can self-register).
4. **Identity → Services → Git Gateway → Enable Git Gateway** (this is the step that connects Git Gateway to the already-linked `digistaff-team/skaz-kray-astro` repo — the repo setting itself is not editable, it just follows the site's connected repo from step 1).
5. **Identity → Invite users** → enter the editor's email.
6. Note the site's default domain (Site settings → Site details → Site name, e.g. `random-name-123.netlify.app`) and give it to me — I'll replace `NETLIFY_SITE` in `scripts/gen-editor-config.mjs`, regenerate `public/editor/config.yml`, commit, and push.
7. The editor receives an invite email. **They should not just click the link** — they should copy it and, before opening, insert `/editor/` right after the domain and before the `#` (e.g. `https://random-name-123.netlify.app/editor/#invite_token=...`), then open that URL. This lands on our editor page (which has the Identity widget loaded) and should pop up a "set your password" prompt.
8. After setting the password, the editor goes to `https://skaz-kray.ru/editor/` and logs in with their email + the password they just set.
9. Confirm with the editor that they can open and publish a test «Новости» entry — publishing should appear on `https://skaz-kray.ru/` within ~10 minutes via the existing autodeploy cron.
