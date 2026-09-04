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
      - { name: cover, label: "Обложка (фото)", widget: tgimage, required: false }
      - { name: gallery, label: "Галерея фото", widget: tggallery, required: false }
      - { name: body, label: "Текст новости", widget: markdown }
      - { name: categories, widget: hidden, default: ["novosti"] }`;
