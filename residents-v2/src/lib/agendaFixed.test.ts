// Порт residents/tests/CouncilAgendaRepositoryTest.php на node:test: здесь только
// чистая часть — сверка постоянных пунктов (planFixed) и порог sort для новых
// обычных (nextCustomSort). Запись этих действий в БД делает
// routes/council/meeting.ts.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  FIXED_AGENDA_ITEMS, planFixed, nextCustomSort, type AgendaRow,
} from './agendaFixed';

const [FIRST, SECOND] = FIXED_AGENDA_ITEMS;

/** Строка повестки по умолчанию — обычный необсуждённый пункт. */
function row(over: Partial<AgendaRow> = {}): AgendaRow {
  return { title: 'Вопрос по бюджету', sort: 0, discussed: false, ...over };
}

/** Постоянные пункты лежат как задумано: свои sort, ничего не обсуждено. */
function fixedRows(): AgendaRow[] {
  return FIXED_AGENDA_ITEMS.map((fixed) => ({ title: fixed.title, sort: fixed.sort, discussed: false }));
}

// --- Создание постоянных пунктов (легаси test_ensure_fixed_creates_two_items_at_top) ---

test('пустая повестка: создаются оба постоянных пункта со своими sort', () => {
  const plan = planFixed([]);
  assert.equal(plan.size, FIXED_AGENDA_ITEMS.length);

  const first = plan.get(FIRST.title);
  assert.ok(first?.create, 'пункт нужно создать');
  assert.equal(first?.sort, -2);
  assert.equal(first?.discussedReset, false);

  const second = plan.get(SECOND.title);
  assert.ok(second?.create);
  assert.equal(second?.sort, -1, 'второй пункт — сразу за первым');
});

test('обычный пункт в план не входит, постоянные создаются оба', () => {
  const plan = planFixed([row()]);
  assert.equal(plan.size, 2);
  assert.ok(plan.get(FIRST.title)?.create);
  assert.ok(plan.get(SECOND.title)?.create);
});

// --- Идемпотентность (легаси test_ensure_fixed_is_idempotent) ---

test('повторная сверка чистого состояния не возвращает действий', () => {
  assert.equal(planFixed(fixedRows()).size, 0, 'всё как надо — менять нечего');
  assert.equal(planFixed([...fixedRows(), row({ title: 'Пункт А', sort: 0 })]).size, 0);
});

// --- Сброс sort (легаси: постоянные всегда выше обычных) ---

test('сдвинутый sort постоянного пункта возвращается к исходному', () => {
  const plan = planFixed([{ title: FIRST.title, sort: 5, discussed: false }, ...fixedRows().slice(1)]);
  assert.equal(plan.size, 1, 'второй пункт трогать не нужно');

  const first = plan.get(FIRST.title);
  assert.equal(first?.create, false);
  assert.equal(first?.sort, -2);
  assert.equal(first?.discussedReset, null, 'обсуждение не трогать');
});

// --- Сброс «обсуждено» (легаси test_rollover_keeps_fixed_and_resets_them) ---

test('отмеченный «обсуждено» постоянный пункт сбрасывается', () => {
  const plan = planFixed([{ title: FIRST.title, sort: FIRST.sort, discussed: true }, ...fixedRows().slice(1)]);
  const first = plan.get(FIRST.title);
  assert.equal(first?.create, false);
  assert.equal(first?.sort, null, 'sort в порядке');
  assert.equal(first?.discussedReset, false, 'отметку снять');
});

test('sort и обсуждение чинятся за один проход', () => {
  const plan = planFixed([{ title: FIRST.title, sort: 3, discussed: true }, ...fixedRows().slice(1)]);
  assert.equal(plan.size, 1);
  assert.deepEqual(plan.get(FIRST.title), { create: false, sort: -2, discussedReset: false });
});

test('дубликат строки — реагируем на первую', () => {
  const plan = planFixed([
    { title: FIRST.title, sort: FIRST.sort, discussed: false },
    row({ title: FIRST.title, sort: 9, discussed: true }),
    ...fixedRows().slice(1),
  ]);
  assert.equal(plan.size, 0, 'первая строка уже в порядке — дубликат не чиним');
});

// --- Порог sort для новых обычных пунктов (легаси test_add_keeps_fixed_items_first) ---

test('первый обычный пункт получает sort 0, а не отрицательный', () => {
  assert.equal(nextCustomSort(null), 0, 'обычных пунктов ещё нет');
  assert.equal(nextCustomSort(-1), 0, 'в БД только постоянные (sort < 0)');
  assert.equal(nextCustomSort(-2), 0, 'обычный пункт не может встать выше постоянных');
});

test('следующий обычный пункт — MAX(sort)+1', () => {
  assert.equal(nextCustomSort(0), 1);
  assert.equal(nextCustomSort(6), 7);
});
