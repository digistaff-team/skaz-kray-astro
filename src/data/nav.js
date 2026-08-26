// Header + footer navigation. "Ярмарка" intentionally dropped (не переносим).
// URLs preserve the original WordPress permalink structure.

export const headerNav = [
  { title: 'Главная', url: '/' },
  {
    title: 'О нас', url: '/o-nas/',
    children: [
      { title: 'Образ поселения', url: '/o-nas/obraz-poseleniya/' },
      { title: 'План поселения', url: '/o-nas/plan-poseleniya/' },
      { title: 'Правила', url: '/o-nas/pravila/' },
      { title: 'Новичкам', url: '/o-nas/novichkam/' },
      { title: 'Частые вопросы', url: '/o-nas/chastye-voprosy/' },
    ],
  },
  { title: 'Дневники', url: '/category/dnevniki-pomestij/' },
  {
    title: 'Статьи', url: '/category/stati/',
    children: [
      { title: 'Сказочная кухня', url: '/category/stati/cuisine/' },
      { title: '«Сказочные» репортажи', url: '/category/stati/skazochnye-reportazhi/' },
      { title: 'Копилка знаний', url: '/category/stati/kopilka-znanij/' },
    ],
  },
  {
    // Бывший раздел "Новости" — записи датированы прошлыми годами, теперь это архив.
    title: 'Архив', url: '/category/archive/',
    children: [
      { title: 'Словарь терминов', url: '/arxiv/slovar-terminov/' },
      { title: 'Полезные ссылки', url: '/arxiv/poleznye-ssylki/' },
    ],
  },
  { title: 'Контакты', url: '/kontakty/' },
];

export const footerNav = [
  { title: 'Главная', url: '/' },
  { title: 'О нас', url: '/o-nas/' },
  { title: 'Дневники поместий', url: '/category/dnevniki-pomestij/' },
  { title: 'Статьи', url: '/category/stati/' },
  { title: 'Архив', url: '/category/archive/' },
  { title: 'Контакты', url: '/kontakty/' },
];

export const social = [
  { title: 'ВКонтакте', url: 'https://vk.com/skaz.kray' },
];

export const site = {
  name: 'Сказочный Край',
  tagline: 'Поселение родовых поместий',
  copyright: '© 2013–2026 ПРП Сказочный Край',
};
