// Header + footer navigation.
// URLs preserve the original WordPress permalink structure.
// "Ярмарка" из старого WP-раздела была намеренно исключена при переносе;
// новый пункт "Ярмарка" ниже ведёт в раздел жителей поселения (residents/), это другой сервис.

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
  { title: 'Новости', url: '/category/novosti/' },
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
  { title: 'Дневники поместий', url: '/dnevniki-pomestiy/' },
  { title: 'Ярмарка', url: '/yarmarka/' },
  { title: 'Кабинет жителя', url: '/poselenie/vhod' },
  { title: 'Контакты', url: '/kontakty/' },
];

export const footerNav = [
  { title: 'Главная', url: '/' },
  { title: 'О нас', url: '/o-nas/' },
  { title: 'Новости', url: '/category/novosti/' },
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
