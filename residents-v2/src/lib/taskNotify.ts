/**
 * Уведомление исполнителя о поставленной задаче — порт
 * residents/src/Controller/Council/TaskController::notifyAssignee + priorityView.
 *
 * Сообщение приходит в @SkazKray_bot и содержит inline-кнопки «Взял в работу» и
 * (для чужой задачи) «Отказался»; нажатия обрабатывает council/webhook.ts по
 * callback_data вида t:<taskId>:take|decline. Кнопки прогресса («20/50/80%»,
 * «Выполнена») webhook добавляет сам, переписывая сообщение.
 *
 * Отличия от легаси, продиктованные serverless:
 *  - легаси отправлял сообщение ПОСЛЕ ответа клиенту (fastcgi_finish_request),
 *    чтобы задержка Bot API не тормозила сохранение задачи. Здесь такой возможности
 *    нет: функция замораживается по окончании события, поэтому отправка выполняется
 *    ДО ответа — как и requestExpenseIfNeeded в councilExpense.ts.
 *  - fail-closed: любой сбой (нет env, нет telegram_id, сеть) только логируется и
 *    не роняет запрос — задача остаётся сохранённой.
 *
 * Токен/env читаются в момент вызова (урок Vercel: module-level → undefined).
 */
import { db } from './db';
import { TelegramBot } from './telegramBot';

const TASKS_PATH = '/sovet/zadachi';

/** base64url(путь) без padding — тот же deepLink, что в meetingNotify.ts. */
function deepLink(base: string, path: string): string {
  const code = Buffer.from(path, 'utf8').toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  return base.replace(/\/+$/, '') + '?startapp=' + code;
}

/** Прямая ссылка на раздел «Текущие задачи» в мини-приложении Совета. */
export function tasksDeepLink(): string {
  const base = process.env.COUNCIL_APP_LINK || 'https://t.me/SkazKray_bot/sovet';
  return deepLink(base, TASKS_PATH);
}

/** «14 ноября 2026» — порт ru_date() из helpers.php (легаси писал срок по-русски). */
export function ruDate(s: string): string {
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  if (!m) return s;
  const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  return `${Number(m[3])} ${months[Number(m[2]) - 1]} ${m[1]}`;
}

/** Приоритет в мужском роде (для строки «Приоритет: …») + цветной кружок — порт priorityView. */
export function priorityView(p: string): [string, string] {
  switch (p) {
    case 'высокая': return ['высокий', '🔴'];
    case 'средняя': return ['средний', '🟠'];
    case 'низкая':  return ['низкий',  '🟢'];
    default:        return [p, '⚪'];
  }
}

/** Как htmlspecialchars(…, ENT_QUOTES) в легаси: текст идёт в сообщение с parse_mode=HTML. */
function esc(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export interface TaskNotifyInput {
  /** Исполнитель из формы (имя члена совета); пусто — уведомление не нужно. */
  assignee: string;
  /** Кто поставил задачу — имя вошедшего, из сессии, не из формы. */
  author: string;
  title: string;
  priority: string;
  /** Срок в YYYY-MM-DD (или null — «не задан»). */
  dueDate: string | null;
  link: string;
}

/** Текст сообщения — порт сборки $text из notifyAssignee. */
export function taskNotifyText(input: TaskNotifyInput): string {
  const assignee = input.assignee.trim();
  // Самоназначение: свой заголовок, без строки «Поставил».
  const self = assignee === input.author;
  const [prioWord, prioEmoji] = priorityView(input.priority);
  const due = input.dueDate ? ruDate(input.dueDate) : 'не задан';

  const lines = [
    (self ? '👋 Вы взяли себе задачу' : '👋 Вам поставлена задача:'),
  ];
  if (!self) lines.push('😃 Поставил: ' + esc(input.author));
  lines.push('🌟 ' + esc(input.title));
  lines.push(prioEmoji + ' Приоритет: ' + esc(prioWord));
  lines.push('⏰ Срок: ' + esc(due));
  lines.push('');
  // Ссылку Telegram показывает как «Подробнее»: тег не экранируем, href — да.
  lines.push('<a href="' + esc(input.link) + '">Подробнее</a>');
  return lines.join('\n');
}

/**
 * Клавиатура под сообщением. От своей задачи отказаться нельзя — для
 * самоназначения остаётся только «Взял в работу» (порт $buttons).
 */
export function taskNotifyKeyboard(taskId: number, self: boolean): string {
  const row: Array<{ text: string; callback_data: string }> = [
    { text: '✅ Взял в работу', callback_data: `t:${taskId}:take` },
  ];
  if (!self) {
    row.push({ text: '🚫 Отказался', callback_data: `t:${taskId}:decline` });
  }
  return JSON.stringify({ inline_keyboard: [row] });
}

export interface NotifyAssigneeParams {
  taskId: number;
  assignee: string;
  author: string;
  title: string;
  priority: string;
  dueDate: string | null;
}

/**
 * Поставить задачу на уведомление: найти Telegram-аккаунт исполнителя и отправить
 * сообщение с кнопками. true — доставлено.
 */
export async function notifyAssignee(p: NotifyAssigneeParams): Promise<boolean> {
  try {
    const assignee = p.assignee.trim();
    if (assignee === '') return false;

    const token = process.env.SKAZKRAY_BOT_TOKEN || '';
    if (token === '') return false;

    const member = await db.councilMember.findFirst({
      where: { name: assignee },
      select: { telegramId: true },
    });
    if (!member?.telegramId) return false;

    const self = assignee === p.author;
    const text = taskNotifyText({
      assignee,
      author: p.author,
      title: p.title,
      priority: p.priority,
      dueDate: p.dueDate,
      link: tasksDeepLink(),
    });
    const keyboard = taskNotifyKeyboard(p.taskId, self);

    const sent = await TelegramBot.sendMessage(token, String(member.telegramId), text, {
      parseMode: 'HTML',
      replyMarkup: keyboard,
    });
    if (!sent) {
      console.error(`taskNotify: не доставлено «${assignee}» (задача ${p.taskId})`);
      return false;
    }
    console.log(`taskNotify: отправлено «${assignee}» (задача ${p.taskId}, msg_id=${sent.messageId})`);
    return true;
  } catch (err) {
    console.error('taskNotify error:', err instanceof Error ? err.message : String(err));
    return false;
  }
}

/** dueDate Prisma (Date | null, @db.Date) → YYYY-MM-DD для текста уведомления. */
export function dueDateText(d: Date | null | undefined): string | null {
  if (!d) return null;
  const iso = d.toISOString().slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso : null;
}
