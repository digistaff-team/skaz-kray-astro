// Generate public/admin/config.yml for Decap CMS, keeping category options
// in sync with src/data/categories.js.
import fs from 'node:fs';
import { novostiCollectionYaml } from './novosti-collection.mjs';
import { articleCollectionsYaml } from './article-collections.mjs';

const yml = `# Decap CMS — редактор контента сайта «Сказочный Край».
# Сгенерировано scripts/gen-decap-config.mjs — правьте генератор, не этот файл.

backend:
  name: github
  repo: digistaff-team/skaz-kray-astro
  branch: main
  base_url: https://skaz-kray.ru
  auth_endpoint: oauth/auth

# Локальное редактирование при разработке:
#   1) npx decap-server   2) npm run dev   3) открыть http://localhost:4321/admin/index.html
local_backend: true

locale: ru
site_url: https://skaz-kray.ru
display_url: https://skaz-kray.ru
media_folder: "public/wp-content/uploads/decap"
public_folder: "/wp-content/uploads/decap"

collections:
${novostiCollectionYaml}

${articleCollectionsYaml()}

  - name: pages
    label: "Страницы"
    label_singular: "Страница"
    description: "Обычные страницы сайта (О нас, Правила и т.д.)."
    folder: "src/content/pages"
    create: true
    slug: "{{slug}}"
    identifier_field: title
    fields:
      - { name: title, label: "Заголовок", widget: string }
      - { name: permalink, label: "Адрес страницы (URL)", widget: string, hint: "например /o-nas/pravila/ — со слэшами" }
      - { name: date, label: "Дата", widget: datetime, required: false, date_format: "YYYY-MM-DD", time_format: "HH:mm:ss", format: "YYYY-MM-DD HH:mm:ss" }
      - { name: seoTitle, label: "SEO: заголовок", widget: string, required: false }
      - { name: seoDescription, label: "SEO: описание", widget: text, required: false }
      - { name: body, label: "Текст", widget: markdown }
`;

fs.writeFileSync('C:/Projects/skaz-kray-astro/public/admin/config.yml', yml);
console.log('Wrote public/admin/config.yml');
