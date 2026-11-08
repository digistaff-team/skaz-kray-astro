import { Response } from 'express';
import { db } from './db';
import { CouncilPayload } from './auth';

/**
 * Общие хелперы раздела Совета — порт того, что в легаси раскидано по
 * CouncilAuth/CouncilData и повторяется в каждом контроллере Council/*.
 */

/** Единый формат ошибки API (легаси отвечал редиректом с Flash — в API это код+текст). */
export function httpError(res: Response, status: number, error: string): void {
  res.status(status).json({ error });
}

/** Имя члена совета по id — автор операций берётся из сессии, не из формы. */
export async function councilName(memberId: number): Promise<string> {
  const m = await db.councilMember.findUnique({
    where: { id: memberId },
    select: { name: true },
  });
  return m?.name ?? '';
}

/**
 * Редактор встречи/повестки: админ ИЛИ текущий Дежурный председатель
 * (MeetingController::requireEditor / AgendaController::isEditor в легаси).
 * Роль дежурного переходящая, поэтому проверяется флаг в БД, а не токен.
 */
export async function isCouncilEditor(council: CouncilPayload): Promise<boolean> {
  if (council.role === 'admin') return true;
  const m = await db.councilMember.findUnique({
    where: { id: council.memberId },
    select: { isDutyChair: true },
  });
  return m?.isDutyChair ?? false;
}

/** Допустимые значения — как константы легаси-контроллеров. */
export const TASK_PRIORITIES = ['низкая', 'средняя', 'высокая'] as const;
export const TASK_STATUSES = ['новая', 'в работе', 'выполнена'] as const;
export const TASK_SORTS = ['priority', 'created', 'progress', 'spent'] as const;
/** Когда нужны деньги по задаче: post — возмещение после выполнения, pre — предоплата. */
export const EXPENSE_TIMINGS = ['post', 'pre'] as const;
export const LEDGER_KINDS = ['income', 'expense'] as const;

export type TaskPriority = (typeof TASK_PRIORITIES)[number];
export type TaskStatus = (typeof TASK_STATUSES)[number];
export type TaskSort = (typeof TASK_SORTS)[number];
export type ExpenseTiming = (typeof EXPENSE_TIMINGS)[number];

export function pickPriority(v: unknown): TaskPriority {
  return TASK_PRIORITIES.includes(v as TaskPriority) ? (v as TaskPriority) : 'средняя';
}

export function pickStatus(v: unknown): TaskStatus | null {
  return TASK_STATUSES.includes(v as TaskStatus) ? (v as TaskStatus) : null;
}

export function pickSort(v: unknown): TaskSort {
  return TASK_SORTS.includes(v as TaskSort) ? (v as TaskSort) : 'created';
}

export function pickTiming(v: unknown): ExpenseTiming {
  const s = typeof v === 'string' ? v.trim() : '';
  return EXPENSE_TIMINGS.includes(s as ExpenseTiming) ? (s as ExpenseTiming) : 'post';
}

/** Валидная дата YYYY-MM-DD или null (пустая строка = «срок не задан»). */
export function pickDate(v: unknown): Date | null {
  const s = typeof v === 'string' ? v.trim() : '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
  const d = new Date(s + 'T00:00:00Z');
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Сумма из свободного ввода («1 250,50» → 1250.5), не меньше нуля. */
export function parseAmount(v: unknown): number {
  const raw = typeof v === 'string' ? v.replace(/\s/g, '').replace(',', '.') : v;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

/**
 * Статья бюджета нужного вида — проверка существования и типа (легаси
 * pickCategory / create проверяли, что статья существует и совпадает по kind).
 */
export async function pickCategory(raw: unknown, kind: 'income' | 'expense'): Promise<number | null> {
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) return null;
  const cat = await db.councilLedgerCategory.findUnique({
    where: { id },
    select: { kind: true },
  });
  return cat && cat.kind === kind ? id : null;
}

/**
 * Скрытые поля ответа: пароли/коды привязки не должны уходить в JSON даже
 * случайно (redactSecrets в server.ts ловит password_hash, но не claim_code_hash).
 */
export function publicMember<T extends { passwordHash?: string; claimCodeHash?: string | null }>(
  m: T,
): Omit<T, 'passwordHash' | 'claimCodeHash'> {
  const { passwordHash: _p, claimCodeHash: _c, ...rest } = m;
  return rest;
}
