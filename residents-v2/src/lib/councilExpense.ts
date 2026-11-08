/**
 * Одобрение расходов по задачам Совета — порт Service/CouncilExpenseApproval.
 *
 * Поток: задача с суммой (spent) и статьёй расхода (expenseCategoryId) уходит
 * казначею в Telegram с кнопками «Одобрить»/«Отклонить». Расход попадает в бюджет
 * (council_ledger_entries) ТОЛЬКО после «Одобрить».
 *
 * Момент запроса задаёт expenseTiming:
 *   post (по умолчанию) — возмещение: запрос, когда задача «выполнена»;
 *   pre                 — предоплата: запрос сразу при сохранении задачи.
 *
 * expenseStatus: none → pending → approved | rejected.
 * Единственная точка записи расхода задачи в бюджет — syncExpense().
 *
 * ОТЛИЧИЕ ОТ ЛЕГАСИ: отправка запроса казначею через Telegram-бота (sendRequest)
 * пока не портирована — нужен bot token и chat казначея в env. Статус pending
 * выставляется, но письмо в Telegram не уходит: кнопки одобрения в боте появятся
 * при подключении webhook'а. Сами статусы, гейты и запись в бюджет работают.
 */
import { db } from './db';

type TaskRow = {
  id: number;
  title: string;
  description: string | null;
  author: string;
  status: string;
  spent: unknown;
  expenseCategoryId: number | null;
  expenseTiming: string;
  expenseStatus: string;
};

/** У задачи есть расход, подлежащий одобрению: указаны и сумма, и статья. */
export function hasExpense(t: Pick<TaskRow, 'spent' | 'expenseCategoryId'>): boolean {
  return Number(t.spent ?? 0) > 0 && (t.expenseCategoryId ?? 0) > 0;
}

/** Предоплата: деньги нужны заранее, запрос не ждёт выполнения задачи. */
export function isPrepaid(t: Pick<TaskRow, 'expenseTiming'>): boolean {
  return (t.expenseTiming || 'post') === 'pre';
}

/**
 * Настал момент просить деньги: расход указан и предоплата — сразу,
 * постоплата — только когда задача выполнена.
 */
export function needsRequest(t: Pick<TaskRow, 'spent' | 'expenseCategoryId' | 'expenseTiming' | 'status'>): boolean {
  if (!hasExpense(t)) return false;
  return isPrepaid(t) || t.status === 'выполнена';
}

/**
 * Синхронизировать расход задачи с бюджетом. Операция создаётся/обновляется
 * ТОЛЬКО когда расход одобрен; иначе удаляется. Вызывается после любого
 * изменения задачи — единственная точка записи в бюджет из задач.
 */
export async function syncExpense(taskId: number): Promise<void> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) {
    await db.councilLedgerEntry.deleteMany({ where: { sourceTaskId: taskId } });
    return;
  }

  // Висящий запрос больше не актуален (убрали расход / вернули в работу) — снять pending.
  if (task.expenseStatus === 'pending' && !needsRequest(task)) {
    await db.councilTask.update({
      where: { id: taskId },
      data: { expenseStatus: 'none', expenseMsgChatId: null, expenseMsgId: null },
    });
  }

  if (hasExpense(task) && task.expenseStatus === 'approved') {
    // Дата расхода — дата выполнения (post) или сегодня (pre).
    const when = task.completedAt ?? new Date();
    const entryDate = new Date(when.getFullYear(), when.getMonth(), when.getDate());
    await db.councilLedgerEntry.upsert({
      where: { sourceTaskId: taskId },
      update: {
        categoryId: task.expenseCategoryId!,
        amount: Number(task.spent),
        entryDate,
        note: task.title.slice(0, 300),
      },
      create: {
        kind: 'expense',
        categoryId: task.expenseCategoryId!,
        amount: Number(task.spent),
        entryDate,
        note: task.title.slice(0, 300),
        author: task.author.slice(0, 160),
        sourceTaskId: taskId,
      },
    });
  } else {
    await db.councilLedgerEntry.deleteMany({ where: { sourceTaskId: taskId } });
  }
}

/**
 * Пометить pending и «отправить» запрос казначею, если настал момент
 * (порт requestIfNeeded). См. отличие в шапке файла про Telegram.
 * @returns true, если запрос отправлен (в v2 — статус выставлен).
 */
export async function requestExpenseIfNeeded(taskId: number): Promise<boolean> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) return false;
  if (!needsRequest(task)) return false;
  if (task.expenseStatus === 'approved' || task.expenseStatus === 'pending') return false;

  await db.councilTask.update({
    where: { id: taskId },
    data: { expenseStatus: 'pending', expenseMsgChatId: null, expenseMsgId: null },
  });
  return true;
}

/** Одобрить расход задачи — расход попадает в бюджет (порт approve). */
export async function approveExpense(taskId: number): Promise<boolean> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task || !hasExpense(task)) return false;
  await db.councilTask.update({
    where: { id: taskId },
    data: { expenseStatus: 'approved', expenseMsgChatId: null, expenseMsgId: null },
  });
  await syncExpense(taskId);
  return true;
}

/** Отклонить расход: убрать из бюджета (порт reject; уведомление исполнителю — позже). */
export async function rejectExpense(taskId: number): Promise<boolean> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) return false;
  await db.councilTask.update({
    where: { id: taskId },
    data: { expenseStatus: 'rejected', expenseMsgChatId: null, expenseMsgId: null },
  });
  await syncExpense(taskId);
  return true;
}