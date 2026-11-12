// Постоянные пункты повестки Совета — порт CouncilAgendaRepository::ensureFixed
// из легаси (residents/): всегда первые в списке, их нельзя удалить/переместить,
// при переносе встречи они не исчезают и сбрасываются в необсуждённое.
//
// Чистая функция возвращает действия для вызывающего кода (routes/council/meeting.ts),
// чтобы логику можно было гонять в unit-тестах без БД.

/** Постоянные пункты: title и отрицательный sort — всегда выше обычных (sort >= 0). */
export const FIXED_AGENDA_ITEMS = [
  { title: 'Изменения в составе Совета', sort: -2 },
  { title: 'Статус по текущим задачам', sort: -1 },
] as const;

export interface AgendaRow {
  title: string;
  sort: number;
  discussed: boolean;
}

/** Матрица: как должна выглядеть строка (sort/discard) и создавать ли её. */
export interface FixedPlan {
  create: boolean;
  /** sort, который должен быть у строки (null — не менять). */
  sort: number | null;
  /** сбросить отметку «обсуждено» (null — не трогать). */
  discussedReset: boolean | null;
}

/**
 * Сверка текущих строк с постоянными: для каждого пункта — создать ли его,
 * сбросить ли sort/дискуссию. Идемпотентно: повторный вызов без изменений
 * возвращает пустые действия.
 */
export function planFixed(rows: readonly AgendaRow[]): Map<string, FixedPlan> {
  const byTitle = new Map<string, AgendaRow>();
  for (const row of rows) {
    // Если вдруг задублировали — реагируем на первую.
    if (!byTitle.has(row.title)) byTitle.set(row.title, row);
  }

  const plan = new Map<string, FixedPlan>();
  for (const fixed of FIXED_AGENDA_ITEMS) {
    const row = byTitle.get(fixed.title);
    if (!row) {
      plan.set(fixed.title, { create: true, sort: fixed.sort, discussedReset: false });
      continue;
    }
    const sortOk = row.sort === fixed.sort;
    const discussedOk = !row.discussed;
    if (sortOk && discussedOk) continue; // уже как надо — действий нет
    plan.set(fixed.title, {
      create: false,
      sort: sortOk ? null : fixed.sort,
      discussedReset: discussedOk ? null : false,
    });
  }
  return plan;
}

/** sort для нового обычного пункта: MAX(sort)+1, но не меньше 0 (порог фикс-пунктов). */
export function nextCustomSort(maxSort: number | null): number {
  const next = (maxSort ?? -1) + 1;
  return next < 0 ? 0 : next;
}