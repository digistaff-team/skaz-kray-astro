import { db } from '../lib/db';
import { Decimal } from '@prisma/client/runtime/library';

/**
 * Собирает модель наглядного отчёта «Бюджет Общего дома» из БД (Prisma).
 * Единый источник правды: та же модель, что на странице совета (цифры идентичны).
 * Ничего не пишет, только читает.
 *
 * build(selectedYm?) → [
 *   'months'       => [{ ym, label, income, expense, balance }, ...] (новые сверху),
 *   'totalIncome','totalExpense','totalBalance' => number (за всё время),
 *   'monthIncome','monthExpense','monthBalance' => number (выбранный месяц),
 *   'selectedYm'   => 'YYYY-MM'|null, 'selectedLabel' => string,
 *   'breakdown'    => [{ name, sum, pct, share, color }, ...] (расходы месяца),
 *   'operations'   => [{ id, kind, categoryId, category, amount, entry_date, note, hasReceipt, receiptPath, fromTask, taskId }, ...],
 * ]
 */

const MONTHS_RU = [
  '', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
];

const PALETTE = [
  '#3f9b6d', '#e0a458', '#c0603a', '#6b8fb5',
  '#9b7cb0', '#8aa63f', '#c98aa6', '#7ba0a8',
];

/** Преобразует Decimal в number для JSON. */
function toNum(d: Decimal | number | null | undefined): number {
  if (d === null || d === undefined) return 0;
  return typeof d === 'number' ? d : parseFloat(String(d));
}

function monthRange(ym: string): { gte: Date; lt: Date } {
  const [y, m] = ym.split('-').map(Number);
  const gte = new Date(y, m - 1, 1);
  const lt = new Date(y, m, 1);
  return { gte, lt };
}

export async function buildLedgerReport(selectedYm: string | null = null): Promise<{
  months: Array<{
    ym: string;
    label: string;
    income: number;
    expense: number;
    balance: number;
  }>;
  totalIncome: number;
  totalExpense: number;
  totalBalance: number;
  monthIncome: number;
  monthExpense: number;
  monthBalance: number;
  selectedYm: string | null;
  selectedLabel: string;
  breakdown: Array<{
    name: string;
    sum: number;
    pct: number;
    share: number;
    color: string;
  }>;
  operations: Array<{
    id: number;
    kind: string;
    categoryId: number;
    category: string;
    amount: number;
    entry_date: string;
    note: string;
    hasReceipt: boolean;
    receiptPath: string | null;
    fromTask: boolean;
    taskId: number;
  }>;
}> {
  // Все уникальные месяцы с данными — новые сверху
  const monthsListRaw = await db.councilLedgerEntry.findMany({
    select: { entryDate: true },
    distinct: ['entryDate'],
    orderBy: { entryDate: 'desc' },
  });

  const ymSet = new Set<string>();
  for (const row of monthsListRaw) {
    if (!row.entryDate) continue;
    const d = new Date(row.entryDate);
    ymSet.add(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  }

  const monthsList = Array.from(ymSet).sort((a, b) => b.localeCompare(a));

  // Суммы по месяцам и видам (entryDate в схеме NOT NULL — фильтр не нужен)
  const sumsRaw = await db.councilLedgerEntry.groupBy({
    by: ['kind', 'entryDate'],
    _sum: { amount: true },
  });

  const sumsMap = new Map<string, { income: number; expense: number }>();
  for (const row of sumsRaw) {
    if (!row.entryDate) continue;
    const d = new Date(row.entryDate);
    const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    const entry = sumsMap.get(ym) ?? { income: 0, expense: 0 };
    const val = toNum(row._sum?.amount);
    if (row.kind === 'income') entry.income += val;
    else entry.expense += val;
    sumsMap.set(ym, entry);
  }

  const months = monthsList.map((ym) => {
    const s = sumsMap.get(ym) ?? { income: 0, expense: 0 };
    return {
      ym,
      label: `${MONTHS_RU[parseInt(ym.split('-')[1])] || ''} ${ym.split('-')[0]}`.trim(),
      income: s.income,
      expense: s.expense,
      balance: s.income - s.expense,
    };
  });

  const totalIncome = months.reduce((s, m) => s + m.income, 0);
  const totalExpense = months.reduce((s, m) => s + m.expense, 0);

  // Выбранный месяц: переданный (если есть данные) либо последний
  const chosenYm =
    selectedYm && monthsList.includes(selectedYm) ? selectedYm : monthsList[0] ?? null;

  let breakdown: Array<{ name: string; sum: number; pct: number; share: number; color: string }> = [];
  let operations: Array<{
    id: number; kind: string; categoryId: number; category: string;
    amount: number; entry_date: string; note: string;
    hasReceipt: boolean; receiptPath: string | null; fromTask: boolean; taskId: number;
  }> = [];
  let monthIncome = 0, monthExpense = 0;

  if (chosenYm) {
    const range = monthRange(chosenYm);

    // Суммы доходов/расходов за месяц
    const monthOpsRaw = await db.councilLedgerEntry.findMany({
      where: { entryDate: { gte: range.gte, lt: range.lt } },
      select: { kind: true, amount: true },
    });
    for (const op of monthOpsRaw) {
      if (op.kind === 'income') monthIncome += toNum(op.amount);
      else monthExpense += toNum(op.amount);
    }

    // Расходы по категориям
    const expRows = await db.councilLedgerEntry.findMany({
      where: {
        kind: 'expense',
        entryDate: { gte: range.gte, lt: range.lt },
      },
      select: {
        amount: true,
        category: { select: { name: true } },
      },
      orderBy: { amount: 'desc' },
    });

    const expTotal = expRows.reduce((s, r) => s + toNum(r.amount), 0);
    const iMax = PALETTE.length;
    breakdown = expRows.map((r, i) => ({
      name: r.category?.name ?? 'Без категории',
      sum: toNum(r.amount),
      pct: expTotal > 0 ? Math.round(toNum(r.amount) / expTotal * 100) : 0,
      share: expTotal > 0 ? toNum(r.amount) / expTotal * 100 : 0,
      color: PALETTE[i % iMax],
    }));

    // Операции месяца
    const opsRaw = await db.councilLedgerEntry.findMany({
      where: { entryDate: { gte: range.gte, lt: range.lt } },
      select: {
        id: true, kind: true, categoryId: true, amount: true,
        entryDate: true, note: true, sourceTaskId: true,
        category: { select: { name: true } },
      },
      orderBy: { entryDate: 'desc' },
    });

    // Фото чеков (полиморфная таблица images, owner_type='expense')
    const opIds = opsRaw.map((o) => o.id);
    const receipts = opIds.length > 0
      ? await db.image.findMany({
          where: { ownerType: 'expense', ownerId: { in: opIds } },
          select: { ownerId: true, path: true },
        })
      : [];
    const receiptMap = new Map<number, string>();
    for (const img of receipts) {
      if (!receiptMap.has(img.ownerId)) receiptMap.set(img.ownerId, img.path);
    }

    operations = opsRaw.map((op) => ({
      id: op.id,
      kind: op.kind,
      categoryId: op.categoryId,
      category: op.category?.name ?? '',
      amount: toNum(op.amount),
      entry_date: op.entryDate ? new Date(op.entryDate).toISOString().split('T')[0] : '',
      note: op.note ?? '',
      hasReceipt: receiptMap.has(op.id),
      receiptPath: receiptMap.get(op.id) ?? null,
      fromTask: (op.sourceTaskId ?? 0) > 0,
      taskId: op.sourceTaskId ?? 0,
    }));
  }

  return {
    months,
    totalIncome,
    totalExpense,
    totalBalance: totalIncome - totalExpense,
    monthIncome,
    monthExpense,
    monthBalance: monthIncome - monthExpense,
    selectedYm: chosenYm,
    selectedLabel: chosenYm
      ? `${MONTHS_RU[parseInt(chosenYm.split('-')[1])] || ''} ${chosenYm.split('-')[0]}`.trim()
      : '',
    breakdown,
    operations,
  };
}
