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
const NETLIFY_SITE = 'resplendent-cupcake-1f315a.netlify.app';

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
