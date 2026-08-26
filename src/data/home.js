// Homepage hero slides (curated: nature/settlement photos; event-specific
// "Масленица" and the static map slide dropped) + intro blocks.

export const slides = [
  { img: '/images/slider/01-reka-shebsh.jpg', title: 'Река Шебш', link: null },
  { img: '/images/slider/03-sady.jpg', title: 'Сады в поселении', link: null },
  { img: '/images/slider/04-polya.jpg', title: 'Поля в поселении', link: null },
  { img: '/images/slider/05-vesna.jpg', title: 'Весна в начале марта', link: null },
  { img: '/images/slider/02-sosedi.jpg', title: 'Дружный коллектив соседей', link: null },
  { img: '/images/slider/06-sozdaem.jpg', title: 'Создаём поселение в Краснодарском крае', link: '/o-nas/' },
  { img: '/images/slider/07-zhdet-tebya.jpg', title: 'Сказочный Край ждёт тебя!', link: '/o-nas/obraz-poseleniya/' },
];

// Intro cards under the hero — mirror the original home_page blocks.
export const introBlocks = [
  {
    title: 'О поселении',
    image: '/images/blocks/o-poselenii.jpg',
    text: 'Наше поселение Сказочный Край находится в Северском районе Краснодарского края. Места красивые, участки просторные, вокруг — леса, поля и река Шебш. Мы создаём пространство для жизни семьи в гармонии с природой.',
    link: '/o-nas/',
    linkText: 'Узнать о нас больше',
  },
  {
    title: 'Образ поселения',
    image: '/images/blocks/zdravstvujte.jpg',
    text: 'Мы создаём Сказочный Край — поселение родовых поместий. Каждая семья обустраивает свой гектар: сад, огород, дом, пространство любви, которое будет передаваться из поколения в поколение.',
    link: '/o-nas/obraz-poseleniya/',
    linkText: 'Читать образ поселения',
  },
  {
    title: 'Есть свободные участки',
    image: null,
    text: 'В поселении ещё есть свободные участки под родовые поместья. Познакомьтесь с планом поселения, правилами и подпишитесь на серию писем «Путь в Сказочный Край».',
    link: '/o-nas/plan-poseleniya/',
    linkText: 'Смотреть план поселения',
  },
];
