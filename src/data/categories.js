// Category taxonomy mirrored from WordPress (id, slug, name, parent, count).
// URLs are nested under /category/ preserving WP hierarchy.

export const categories = [
  { id: 200, slug: 'novosti', name: 'Новости', parent: 0, count: 0 },
  { id: 30, slug: 'dnevniki-pomestij', name: 'Дневники поместий', parent: 0, count: 46 },
  { id: 31, slug: 'archive', name: 'Архив', parent: 0, count: 30 },
  { id: 49, slug: 'stati', name: 'Статьи', parent: 0, count: 20 },
  { id: 1, slug: 'bez-rubriki', name: 'Без рубрики', parent: 0, count: 3 },

  { id: 53, slug: 'pomeste-grushko-duby-kolduny', name: 'Поместье Грушко «Дубы колдуны»', parent: 30, count: 11 },
  { id: 36, slug: 'pomeste-rudenko-agudariya', name: 'Поместье Руденко «АгудариЯ»', parent: 30, count: 11 },
  { id: 57, slug: 'pomeste-schastlivoe', name: 'Поместье Кисловых', parent: 30, count: 6 },
  { id: 52, slug: 'pomeste-alxovskix-mechta', name: 'Поместье Альховских «Мечта»', parent: 30, count: 4 },
  { id: 44, slug: 'pomeste-malinovskix', name: 'Поместье Малиновских', parent: 30, count: 4 },
  { id: 55, slug: 'pomeste-dolina-yablon', name: 'Поместье «Долина яблонь»', parent: 30, count: 3 },
  { id: 43, slug: 'pomeste-slavnoe', name: 'Поместье «Славное»', parent: 30, count: 2 },
  { id: 56, slug: 'pomeste-shaninyx', name: 'Поместье Шаниных', parent: 30, count: 2 },
  { id: 127, slug: 'pomeste-andrika', name: 'Поместье «АНДРиКа»', parent: 30, count: 2 },
  { id: 103, slug: 'pomeste-muzyka-vetra', name: 'Поместье «Музыка Ветра»', parent: 30, count: 2 },
  { id: 45, slug: 'pomeste-skazochnye-terema', name: 'Поместье «Сказочные Терема»', parent: 30, count: 1 },
  { id: 48, slug: 'pomeste-vishnyovyj-sad', name: 'Поместье «Вишнёвый сад»', parent: 30, count: 1 },
  { id: 126, slug: 'pomeste-bekkerov', name: 'Поместье Беккеров', parent: 30, count: 1 },
  { id: 140, slug: 'pomeste-sad-mechty', name: 'Поместье «Сад Мечты»', parent: 30, count: 0 },
  { id: 114, slug: 'pomeste-ura', name: 'Поместье «Ура»', parent: 30, count: 0 },
  { id: 110, slug: 'pomeste-shevcovyx', name: 'Поместье Шевцовых', parent: 30, count: 0 },
  { id: 80, slug: 'procvetaniya', name: 'Поместье «Процветания»', parent: 30, count: 0 },

  { id: 51, slug: 'skazochnye-reportazhi', name: '«Сказочные» репортажи', parent: 49, count: 17 },
  { id: 50, slug: 'kopilka-znanij', name: 'Копилка знаний', parent: 49, count: 3 },
  { id: 139, slug: 'cuisine', name: 'Сказочная кухня', parent: 49, count: 1 },
  { id: 142, slug: 'rasteniya-skazochnogo-kraya', name: 'Растения Сказочного Края', parent: 49, count: 0 },
  { id: 150, slug: 'skazochnaya-eko-produkciya', name: 'Сказочная эко-продукция', parent: 49, count: 0 },
];

const byId = Object.fromEntries(categories.map((c) => [c.id, c]));
const byName = Object.fromEntries(categories.map((c) => [c.name, c]));

// Also match the raw WP names with straight quotes (frontmatter uses ")
const byNameLoose = {};
for (const c of categories) {
  byNameLoose[c.name.replace(/[«»„“”]/g, '"').replace(/\s+/g, ' ').trim().toLowerCase()] = c;
}

export function catByName(name) {
  if (byName[name]) return byName[name];
  const key = String(name).replace(/[«»„“”]/g, '"').replace(/\s+/g, ' ').trim().toLowerCase();
  return byNameLoose[key] || null;
}

// Full nested URL for a category, e.g. /category/stati/cuisine/
export function catUrl(cat) {
  if (!cat) return '/';
  const parts = [];
  let c = cat;
  while (c) { parts.unshift(c.slug); c = c.parent ? byId[c.parent] : null; }
  return '/category/' + parts.join('/') + '/';
}

export { byId as catById };
