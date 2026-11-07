import { db } from './db';

/**
 * Разделы приложения (плитки на главной /poselenie/) — порт
 * residents/src/Sections.php: единственный источник правды по набору и
 * заголовкам. Состояние «вкл/выкл» хранит таблица app_sections; отсутствие
 * строки = включён (паритет с isEnabled() легаси).
 */
export const SECTION_LIST: Array<{ key: string; title: string }> = [
  { key: 'dnevniki', title: 'Дневники поместий' },
  { key: 'instrumenty', title: 'Инструменты' },
  { key: 'knigi', title: 'Книги' },
  { key: 'poezdki', title: 'Поездки' },
  { key: 'dostavka', title: 'Доставка' },
  { key: 'obshchiy-dom', title: 'Общий дом' },
  { key: 'byudzhet', title: 'Отчёт о расходах' },
  { key: 'yarmarka', title: 'Ярмарка' },
  { key: 'zakupki', title: 'Закупки' },
  { key: 'sosedi', title: 'Наши соседи' },
];

/** Включённые разделы в порядке SECTION_LIST (выключенные — не отдаются). */
export async function enabledSections(): Promise<Array<{ key: string; title: string }>> {
  const disabled = await db.appSection.findMany({
    where: { enabled: false },
    select: { sectionKey: true },
  });
  const off = new Set(disabled.map((r) => r.sectionKey));
  return SECTION_LIST.filter((s) => !off.has(s.key));
}
