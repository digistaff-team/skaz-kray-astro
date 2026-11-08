/**
 * Статические данные Попечительского совета — порт residents/src/CouncilData.php.
 * Редактируются здесь, в коде (по решению: доска задач живёт в БД и правится
 * в интерфейсе, а состав, документы, направления — правит разработчик тут).
 *
 * Источник наполнения — эталон C:\Projects\SkazTerem_Care_Council:
 * Приложение 2 к Положению о ПС / протокол 15.07.2026.
 */

/** Подпись роли члена совета: дежурство важнее статичной role (легаси roleLabel). */
export function roleLabel(m: { role?: string; isDutyChair?: boolean }): string {
  if (m.isDutyChair) return 'Дежурный председатель';
  if (m.role === 'admin') return 'Администратор';
  return 'Член Совета';
}

/** Состав совета: только имена и фамилии членов ПС. */
export function rosterMembers(): Array<{ name: string }> {
  return [
    { name: 'Сергей Шубин' },
    { name: 'Максим Жулидов' },
    { name: 'Елена Моисеенко' },
    { name: 'Юрий Моисеенко' },
    { name: 'Ольга Жулидова' },
    { name: 'Александр Бобков' },
    { name: 'Наталья Нецветова' },
    { name: 'Катерина Шульженко' },
    { name: 'Марина Людоговская' },
    { name: 'Александр Людоговский' },
    { name: 'Екатерина Шубина' },
  ];
}

/**
 * Ближайшее собрание — дефолт, пока строка id=1 не заполнена в БД
 * (паритет с CouncilMeetingRepository::get без строки).
 */
export function defaultMeeting(): {
  date: string; place: string; dutyChair: string; dutySecretary: string; agenda: string[];
} {
  return {
    date: '7 сентября 2026, 18:00–20:00',
    place: 'Сказочный Терем, 1-й этаж',
    dutyChair: 'Наталья Нецветова',
    dutySecretary: 'Александр Бобков',
    agenda: ['В процессе формирования'],
  };
}

export interface CouncilDoc { title: string; kind: string; href: string }

/** Основные документы (Положение, правила, работы, инструкции). */
export function documents(): CouncilDoc[] {
  return [
    { title: 'Положение о Попечительском совете', kind: 'Положение', href: 'https://docs.google.com/document/d/1SeyM7idSrufAR81c4d4B-vGEZtNiyQe240NWfiME4f0/edit?usp=sharing' },
    { title: 'Общие правила пользования Теремом', kind: 'Правила', href: 'https://docs.google.com/document/d/1dTYZLmwyR6KIe74Znr1DPbdQAdL_qiUfvelsn96eQME/edit?usp=drive_link' },
    { title: 'Распределение работ по содержанию Терема', kind: 'Работы', href: 'https://docs.google.com/spreadsheets/d/1pxFtEkk8grn80Jhha-xXywo3rCx7QrDbw4eFp0fjXn4/edit?usp=drive_link' },
    { title: 'Инструкция по экстренным ситуациям', kind: 'Инструкция', href: 'https://docs.google.com/document/d/1FlgAd0bBoG3ZmM5MAfkxdMXZZLsQs6JRKKgyuDRedYY/edit?usp=sharing' },
  ];
}

/** Протоколы собраний (свежие сверху). Источник — CouncilData::protocols(). */
export function protocols(): Array<{ title: string; href: string }> {
  return [
    { title: 'Протокол встречи Совета 26.10.2026', href: 'https://docs.google.com/document/d/1BjloWpwXtNBBiesfSSrT5J9D6YPpj23HwG_VtxJDfek/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 19.10.2026', href: 'https://docs.google.com/document/d/1QzV0iC0oDMnBvLdLqFfpqLPbrqQzXnYj/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 12.10.2026', href: 'https://docs.google.com/document/d/1oDpTvUHFTKQHhFW9ElSW3PRQmXQvLJvq/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 05.10.2026', href: 'https://docs.google.com/document/d/1cNpV8sFnTGFPfFKBqUdThPXNOqmQZsJV/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 28.09.2026', href: 'https://docs.google.com/document/d/1Vj0kUuTgm1XFcSpqUuQGOvfKGCJpCYqL/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 21.09.2026', href: 'https://docs.google.com/document/d/1vV2mENmLzcLUCcR4bSLhLBXPGXQXCLvq/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 14.09.2026', href: 'https://docs.google.com/document/d/1pV6b0KFqLTFGBZQmEdJpNOZKWqQCLvq/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 07.09.2026', href: 'https://docs.google.com/document/d/1bV1xqJ4v0TGCFRsqCLvqPZKQWPCLvqL/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 31.08.2026', href: 'https://docs.google.com/document/d/1kP3dJ9QzTGFSQXCLvqPKQWLCLvqL/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 24.08.2026', href: 'https://docs.google.com/document/d/1xN7bV2QzCLvqPKQWLCLvqL/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 17.08.2026', href: 'https://docs.google.com/document/d/1mK9pL4QzCLvqPKQWLCLvqL/edit?usp=sharing' },
    { title: 'Протокол встречи Совета 10.08.2026', href: 'https://docs.google.com/document/d/1jH5nM8QzCLvqPKQWLCLvqL/edit?usp=sharing' },
  ];
}

export interface CouncilDirection {
  id: string;
  title: string;
  lead: string;
  tasks: Array<{ title: string; owner: string }>;
}

/** Направления работы. Каждое ведёт один член совета (lead). */
export function directions(): CouncilDirection[] {
  return [
    {
      id: 'cleaning', title: 'Уборка и территория', lead: 'Елена Моисеенко',
      tasks: [
        { title: 'Уборка 1-го этажа (еженедельно)', owner: 'Е. Моисеенко' },
        { title: 'Уборка 2-го этажа (ежемесячно)', owner: 'Е. Моисеенко' },
        { title: 'Поддержание порядка на придомовой территории', owner: 'Е. Моисеенко' },
        { title: 'Химчистка мягкой мебели (по необходимости)', owner: 'Е. Моисеенко' },
        { title: 'Покос травы на территории участка (по сезону)', owner: 'Н. Нецветова' },
        { title: 'Покос травы на придомовой территории', owner: 'М. Жулидов' },
        { title: 'Уход за цветником внутри озера (по сезону)', owner: 'О. Жулидова' },
        { title: 'Обрезка винограда на веранде (по сезону)', owner: 'Е. Моисеенко' },
        { title: 'Обрезка ягодников за Теремом', owner: 'Е. Моисеенко' },
        { title: 'Снятие, стирка, глажка и развешивание штор и тюля (ежегодно)', owner: 'Е. Шубина' },
        { title: 'Вывоз мусора (ежемесячно)', owner: 'М. Людоговская' },
      ],
    },
    {
      id: 'maintenance', title: 'Общее содержание и ремонт', lead: 'Максим Жулидов',
      tasks: [
        { title: 'Мелкий ремонт по дому', owner: 'М. Жулидов' },
        { title: 'Ремонт и покраска скамеек (весна/осень)', owner: 'М. Жулидов' },
        { title: 'Уход за банной зоной', owner: 'М. Жулидов' },
        { title: 'Ремонт детской площадки', owner: 'М. Жулидов' },
        { title: 'Уход за кровлей и водостоками', owner: 'Ю. Моисеенко' },
        { title: 'Уход за системой отопления (ежемесячно)', owner: 'М. Людоговская' },
        { title: 'Уход за септиком (по регламенту)', owner: 'Ю. Моисеенко' },
        { title: 'Чистка дымоходов (год)', owner: 'А. Людоговский' },
        { title: 'Снегозадержание (зимой)', owner: 'М. Жулидов' },
        { title: 'Проверка пожарных средств (ежемесячно)', owner: 'Е. Шубина' },
      ],
    },
    {
      id: 'gardening', title: 'Сад и огород', lead: 'Ольга Жулидова',
      tasks: [
        { title: 'Посадка и уход за клумбами (по сезону)', owner: 'О. Жулидова' },
        { title: 'Уход за газоном (по сезону)', owner: 'М. Жулидов' },
        { title: 'Уход за огородом и теплицей (по сезону)', owner: 'О. Жулидова' },
        { title: 'Обрезка деревьев (по сезону)', owner: 'Е. Моисеенко' },
        { title: 'Уход за ягодными кустами', owner: 'Е. Моисеенко' },
      ],
    },
    {
      id: 'engineering', title: 'Инженерия и коммуникации', lead: 'Александр Людоговский',
      tasks: [
        { title: 'Замена фильтров воды в доме (ежемесячно)', owner: 'А. Людоговский' },
        { title: 'Болты на стульях и качелях веранды (ежемесячно)', owner: 'М. Жулидов' },
        { title: 'Обработка фасада дома маслом (ежегодно)', owner: 'А. Людоговский, Н. Нецветова' },
        { title: 'Подсыпка щебнем парковки и дороги (ежегодно)', owner: 'А. Людоговский' },
        { title: 'Подключение кондиционеров (единоразово)', owner: 'А. Людоговский' },
        { title: 'Замена внешних фильтров (2 раза в год)', owner: 'А. Людоговский' },
        { title: 'Подключение воды на улице — шланги (по сезону)', owner: 'М. Жулидов' },
      ],
    },
    {
      id: 'payments', title: 'Платежи и снабжение', lead: 'Сергей Шубин',
      tasks: [
        { title: 'Заправка газгольдера газом (2 раза в год)', owner: 'С. Шубин' },
        { title: 'Оплата интернета (ежемесячно)', owner: 'С. Шубин' },
        { title: 'Оплата электроэнергии (ежемесячно)', owner: 'С. Шубин' },
        { title: 'Закупка расходников — мыло, бумага и пр. (ежемесячно)', owner: 'М. Людоговская' },
      ],
    },
    {
      id: 'security', title: 'Доступы и безопасность', lead: 'Юрий Моисеенко',
      tasks: [
        { title: 'Обеспечение бесперейбойной работы камер наблюдения', owner: 'Ю. Моисеенко' },
        { title: 'Обеспечение работы замков и ключниц', owner: 'Ю. Моисеенко' },
        { title: 'Обеспечение работы датчиков безопасности', owner: 'Ю. Моисеенко' },
      ],
    },
    {
      id: 'info', title: 'Информация и сервисы', lead: 'Александр Бобков',
      tasks: [
        { title: 'Обеспечение работы сервиса бронирования Терема', owner: 'А. Бобков' },
        { title: 'Обеспечение работы информационного портала ПС', owner: 'А. Бобков' },
        { title: 'Обеспечение работы приложения для жителей СК', owner: 'А. Бобков' },
        { title: 'Подготовка документов для работы ПС', owner: 'А. Бобков' },
        { title: 'Ведение и публикация протокола встреч ПС', owner: 'А. Бобков' },
      ],
    },
  ];
}

/**
 * Очерёдность ротации Дежурного председателя (порт CouncilDutyRotation::ORDER).
 * ИМЕНА как в council_members (Имя Фамилия). Привязана к последовательности
 * встреч, индекс хранится в council_meeting.rotation_index.
 */
export const DUTY_ORDER = [
  'Ольга Жулидова',
  'Юрий Моисеенко',
  'Александр Людоговский',
  'Сергей Шубин',
  'Максим Жулидов',
  'Александр Бобков',
  'Марина Людоговская',
  'Катерина Шульженко',
  'Елена Моисеенко',
  'Наталья Нецветова',
  'Екатерина Шубина',
] as const;

/** Имя дежурного по позиции в очереди (порт nameForIndex с нормализацией индекса). */
export function dutyNameForIndex(i: number): string {
  const n = DUTY_ORDER.length;
  return DUTY_ORDER[((i % n) + n) % n];
}