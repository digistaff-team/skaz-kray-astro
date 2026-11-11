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
 * ОТЛИЧИЕ ОТ ЛЕГАСИ: чат казначея берётся не из конфига, а из council_members
 * (telegram_id того, чьё имя стоит в COUNCIL_EXPENSE_APPROVER) — отдельного
 * секрета для этого нет.
 */
import { db } from './db';
import { TelegramBot } from './telegramBot';

type TaskRow = {
  id: number;
  title: string;
  description: string | null;
  author: string;
  assignee: string;
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

/** Имя казначея — одобряет/отклоняет расходы (легаси Config::council_expense_approver). */
export function approverName(): string {
  return process.env.COUNCIL_EXPENSE_APPROVER || 'Сергей Шубин';
}

/**
 * Пометить pending и отправить запрос казначею, если настал момент
 * (порт requestIfNeeded + sendRequest: статус выставляется ТОЛЬКО когда
 * сообщение реально ушло — иначе кнопки казначея не будет).
 * @returns true, если запрос ушёл.
 */
export async function requestExpenseIfNeeded(taskId: number): Promise<boolean> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) return false;
  if (!needsRequest(task)) return false;
  if (task.expenseStatus === 'approved' || task.expenseStatus === 'pending') return false;

  const sent = await sendExpenseRequest(task);
  if (!sent) return false;

  await db.councilTask.update({
    where: { id: taskId },
    data: { expenseStatus: 'pending', expenseMsgChatId: sent.chatId, expenseMsgId: sent.messageId },
  });
  return true;
}

/**
 * Сообщение казначею с кнопками «Одобрить»/«Отклонить» — порт sendRequest.
 * Токен/чат казначея — env + council_members, сбои не роняют запрос (fail-closed:
 * pending не выставляется, повторная попытка будет при следующем изменении задачи).
 */
/** Экранирование как htmlspecialchars(…, ENT_QUOTES) в легаси (текст уходит с parse_mode=HTML). */
function escHtml(s: string): string {
  // Сущности собираются конкатенацией: цельный '<' в исходнике отдельная
  // запись декодируется в символ ещё до рантайма (как в lib/sovet.js).
  const AMP = '&' + 'amp;';
  const LT = '&' + 'lt;';
  const GT = '&' + 'gt;';
  const QUOT = '&' + 'quot;';
  const APOS = '&' + '#039;';
  return s
    .replace(/&/g, AMP)
    .replace(/</g, LT)
    .replace(/>/g, GT)
    .replace(/"/g, QUOT)
    .replace(/'/g, APOS);
}

/**
 * Текст запроса казначею — порт sendRequest из CouncilExpenseApproval.php.
 * Чистая функция, чтобы тесты гоняли текст без БД и Bot API.
 */
export function expenseRequestText(task: TaskRow): string {
  const pre = isPrepaid(task);
  // Обычный пробел, как number_format(..., ' ', ...) в легаси: toLocaleString
  // для ru-RU ставит NBSP, из-за чего строка расходилась с легаси.
  const amount = Number(task.spent).toLocaleString('ru-RU', { maximumFractionDigits: 0 })
    .replace(/ /g, ' ');
  const e = escHtml;
  return (pre ? '💰 Запрос на финансирование (предоплата)' : '💰 Запрос на возмещение расходов (постоплата)') + '\n'
    + (pre ? 'Создана задача: ' : 'Выполнена задача: ') + e(task.title) + '\n'
    + 'Исполнитель: ' + e(task.assignee.trim() || '—') + '\n'
    + (pre ? 'Предстоящие расходы: ' : 'Расходы: ') + e(amount) + ' руб.';
}

async function sendExpenseRequest(
  task: TaskRow & { expenseTiming: string; status: string },
): Promise<{ chatId: string; messageId: number } | null> {
  const token = process.env.SKAZKRAY_BOT_TOKEN || '';
  if (token === '') return null;

  const approver = await db.councilMember.findFirst({
    where: { name: approverName(), status: 'active' },
    select: { telegramId: true },
  });
  if (!approver?.telegramId) return null;

  const text = expenseRequestText(task);

  const keyboard = JSON.stringify({
    inline_keyboard: [[
      { text: '✅ Одобрить', callback_data: `x:${task.id}:approve` },
      { text: '🚫 Отклонить', callback_data: `x:${task.id}:reject` },
    ]],
  }, null, 0);

  const chatId = String(approver.telegramId);
  const sent = await sendMessageTg(token, chatId, text, keyboard);
  return sent;
}

/** Обёртка над TelegramBot (вынесена, чтобы тесты могли не тянуть Bot API). */
async function sendMessageTg(token: string, chatId: string, text: string, replyMarkup: string):
  Promise<{ chatId: string; messageId: number } | null> {
  const res = await TelegramBot.sendMessage(token, chatId, text, { replyMarkup });
  return res ? { chatId: res.chatId, messageId: res.messageId } : null;
}

/**
 * Уведомление исполнителю об отклонении расхода — порт notifyAssigneeRejected.
 * Чистая функция для тестов; отправка — в rejectExpense.
 */
export function expenseRejectedText(task: TaskRow): string {
  const pre = isPrepaid(task);
  const e = escHtml;
  return (pre
      ? 'Ваш запрос на финансирование предоплаты по Задаче «'
      : 'Ваш запрос на возмещение расходов по выполнению Задачи «')
    + e(task.title) + '» отклонён. '
    + 'Свяжитесь с ' + e(approverName()) + ', чтобы уточнить детали.';
}

/** Отправить исполнителю сообщение об отклонении (fail-closed, как в легаси). */
async function notifyAssigneeRejected(task: TaskRow): Promise<void> {
  try {
    const token = process.env.SKAZKRAY_BOT_TOKEN || '';
    if (token === '') return;
    const assignee = task.assignee.trim();
    if (assignee === '') return;

    const member = await db.councilMember.findFirst({
      where: { name: assignee },
      select: { telegramId: true },
    });
    if (!member?.telegramId) return;

    await TelegramBot.sendMessage(token, String(member.telegramId), expenseRejectedText(task), {
      parseMode: 'HTML',
    });
    console.log(`councilExpense: исполнителю «${assignee}» отправлено об отклонении (задача ${task.id})`);
  } catch (err) {
    console.error('councilExpense reject notify error:', err instanceof Error ? err.message : String(err));
  }
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

/** Отклонить расход: убрать из бюджета и уведомить исполнителя (порт reject). */
export async function rejectExpense(taskId: number): Promise<boolean> {
  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) return false;
  await db.councilTask.update({
    where: { id: taskId },
    data: { expenseStatus: 'rejected', expenseMsgChatId: null, expenseMsgId: null },
  });
  await syncExpense(taskId);
  await notifyAssigneeRejected(task);
  return true;
}