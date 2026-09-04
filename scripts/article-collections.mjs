// Общие коллекции Decap CMS для «видов статей» — раздел «Статьи» и все его
// подрубрики (Сказочная кухня, «Сказочные» репортажи, Копилка знаний и т.д.).
// Используется и /admin/, и /editor/ конфигами, чтобы редактор мог так же
// удобно, как «Новости», добавлять/править статьи каждого вида: отдельная
// вкладка на рубрику, авто-подстановка категории, фильтр по своей рубрике.
//
// Источник рубрик — src/data/categories.js (parent === id рубрики «Статьи»),
// поэтому список синхронизирован автоматически: добавили подрубрику статей —
// появилась вкладка у редактора.
import { categories } from '../src/data/categories.js';

const esc = (s) => String(s).replace(/"/g, '\\"');

/** Одна коллекция-вкладка для рубрики статей (как «Новости», но со своей категорией). */
function articleCollection(cat) {
  return `  - name: art-${cat.slug}
    label: "${esc(cat.name)}"
    label_singular: "Статья"
    description: "Статьи в рубрику «${esc(cat.name)}». Заполните заголовок и текст — рубрика подставится сама."
    folder: "src/content/posts"
    create: true
    filter: { field: categories, value: "${cat.slug}" }
    slug: "{{slug}}"
    identifier_field: title
    sortable_fields: [date, title]
    fields:
      - { name: title, label: "Заголовок", widget: string }
      - { name: date, label: "Дата публикации", widget: datetime, default: "{{now}}", date_format: "YYYY-MM-DD", time_format: "HH:mm:ss", format: "YYYY-MM-DD HH:mm:ss" }
      - { name: excerpt, label: "Краткое описание (для карточек)", widget: text, required: false }
      - { name: cover, label: "Обложка (фото)", widget: tgimage, required: false }
      - name: gallery
        label: "Галерея фото"
        label_singular: "Фото"
        widget: list
        required: false
        summary: "Фото"
        field: { name: image, label: "Фото", widget: tgimage }
      - { name: body, label: "Текст", widget: markdown }
      - { name: categories, widget: hidden, default: ["${cat.slug}"] }`;
}

/** YAML всех коллекций «видов статей»: рубрика «Статьи» + её подрубрики. */
export function articleCollectionsYaml() {
  const stati = categories.find((c) => c.slug === 'stati');
  if (!stati) { return ''; }
  const list = [stati, ...categories.filter((c) => c.parent === stati.id)];
  return list.map(articleCollection).join('\n\n');
}
