// Generate public/editor/config.yml for Decap CMS — вход для редактора
// без GitHub-аккаунта (github backend, но auth_endpoint — своя форма
// пароля на сервере, не GitHub OAuth). См.
// docs/superpowers/specs/2026-08-27-editor-login-v2-design.md
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { novostiCollectionYaml } from './novosti-collection.mjs';
import { articleCollectionsYaml } from './article-collections.mjs';

const yml = `# Decap CMS — вход для редактора (Новости и все виды статей).
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

${articleCollectionsYaml()}
`;

// Путь резолвится относительно расположения этого файла, не хардкод
// абсолютного пути (см. известный баг в gen-decap-config.mjs) — работает
// одинаково и из основного checkout, и из git worktree.
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(scriptDir, '..', 'public', 'editor');
fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(path.join(outDir, 'config.yml'), yml);
console.log('Wrote public/editor/config.yml');
