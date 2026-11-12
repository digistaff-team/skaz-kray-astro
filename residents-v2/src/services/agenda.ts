import { db } from '../lib/db';
import { FIXED_AGENDA_ITEMS, planFixed, nextCustomSort } from '../lib/agendaFixed';

/**
 * Повестка встречи Совета — порт residents/src/Repository/CouncilAgendaRepository.
 *
 * Правила постоянных пунктов (is_fixed) живёт в чистой agendaFixed.planFixed,
 * здесь только применение плана к БД.
 */

/**
 * Гарантировать два постоянных пункта в начале повестки (легаси ensureFixed):
 * отсутствующие создать, сбитый sort вернуть, «обсуждено» снять. Идемпотентно,
 * вызывается при каждом рендере повестки.
 */
export async function ensureFixedAgenda(): Promise<void> {
  // Ищем именно постоянные (легаси: WHERE is_fixed = 1 AND title = ?) — иначе
  // случайно совпавший по названию обычный пункт получил бы их sort, но не защиту.
  const rows = await db.councilAgendaItem.findMany({
    where: { isFixed: true, title: { in: FIXED_AGENDA_ITEMS.map((f) => f.title) } },
    select: { id: true, title: true, sort: true, discussed: true },
  });

  const plan = planFixed(rows);
  const byTitle = new Map(rows.map((r) => [r.title, r]));

  for (const [title, action] of plan) {
    if (action.create) {
      await db.councilAgendaItem.create({
        data: { title, author: '', sort: action.sort ?? 0, isFixed: true },
      });
      continue;
    }
    const id = byTitle.get(title)?.id;
    if (id === undefined) continue;
    await db.councilAgendaItem.update({
      where: { id },
      data: {
        ...(action.sort !== null ? { sort: action.sort } : {}),
        ...(action.discussedReset !== null ? { discussed: action.discussedReset } : {}),
      },
    });
  }
}

/** sort для нового обычного пункта: обычные всегда ниже постоянных (sort >= 0). */
export async function nextCustomAgendaSort(): Promise<number> {
  const max = await db.councilAgendaItem.aggregate({ _max: { sort: true } });
  return nextCustomSort(max._max.sort);
}

/**
 * Перенос повестки на следующую встречу (легаси rollOver): обсуждённые обычные
 * удаляются, остальные обычные помечаются «с прошлой встречи», постоянные
 * переживают перенос без пометок и с сброшенным «обсуждено».
 */
export async function rollOverAgenda(): Promise<number> {
  const [deleted] = await db.$transaction([
    db.councilAgendaItem.deleteMany({ where: { discussed: true, isFixed: false } }),
    db.councilAgendaItem.updateMany({ where: { isFixed: false }, data: { carriedOver: true } }),
    db.councilAgendaItem.updateMany({ where: { isFixed: true }, data: { discussed: false } }),
  ]);
  return deleted.count;
}
