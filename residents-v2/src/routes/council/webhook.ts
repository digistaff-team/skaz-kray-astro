import { Router, Request, Response } from 'express';
import { db } from '../../lib/db';
import { TelegramBot } from '../../lib/telegramBot';
import { DUTY_ACK_LABEL } from '../../lib/dutyNotify';
import {
  approveExpense,
  rejectExpense,
  approverName,
  requestExpenseIfNeeded,
} from '../../lib/councilExpense';

/**
 * Webhook @SkazKray_bot — порт residents/src/Controller/Council/BotWebhookController.
 *
 * Обрабатывает нажатия inline-кнопок:
 *   d:ack:<N>                    — «Дежурство принял» (ротация председателя)
 *   x:<taskId>:approve|reject    — одобрение/отклонение расхода казначеем
 *   t:<taskId>:take|decline|p20|p50|p80|done — рабочие кнопки по задаче
 *
 * Подлинность — заголовок X-Telegram-Bot-Api-Secret-Token (задаётся при
 * setWebhook). Публичный роут: авторизации по cookie нет и быть не может.
 *
 * В отличие от легаси (PHP-FPM + fastcgi_finish_request) здесь Telegram не
 * получает ответ досрочно: на serverless функция может быть заморожена сразу
 * после окончания события, поэтому вся обработка выполняется ДО отправки
 * ответа. Операции быстрые (1–3 запроса к БД + Bot API), а у Telegram на
 * ответ webhook — 60 секунд.
 */

const router = Router();

/** Разбор тела: Telegram шлёт application/json, но express.json() уже отработал. */
function readUpdate(req: Request): unknown {
  return req.body;
}

/**
 * POST /api/council/webhook
 *
 * Всегда 200 (кроме неверного секрета — 403): иначе Telegram считает доставку
 * упавшей и шлёт update повторно, наводняя лог.
 */
router.post('/webhook', async (req: Request, res: Response) => {
  const secret = process.env.SKAZKRAY_WEBHOOK_SECRET || '';
  const got = String(req.headers['x-telegram-bot-api-secret-token'] ?? '');
  if (secret === '' || !safeEqual(secret, got)) {
    res.sendStatus(403);
    return;
  }

  try {
    await dispatchUpdate(readUpdate(req));
  } catch (err) {
    console.error('Council webhook error:', err);
  }
  res.sendStatus(200);
});

/** Сравнение секрета без утечки по времени. */
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

type CallbackQuery = {
  id?: string;
  data?: string;
  from?: { id?: number };
  message?: { chat?: { id?: number | string }; message_id?: number; text?: string };
};

/** Обработка уже проверенного update. Вынесено из HTTP-слоя — чтобы гонять в тестах. */
export async function dispatchUpdate(update: unknown): Promise<void> {
  const cq = (update as { callback_query?: CallbackQuery })?.callback_query;
  if (!cq || typeof cq !== 'object') return;

  const token = process.env.SKAZKRAY_BOT_TOKEN || '';
  const data = String(cq.data ?? '');
  const callbackId = String(cq.id ?? '');
  const fromId = Number(cq.from?.id ?? 0);
  const chatId = String(cq.message?.chat?.id ?? '');
  const messageId = Number(cq.message?.message_id ?? 0);
  const msgText = String(cq.message?.text ?? '');

  if (/^d:ack:(\d+)$/.test(data)) {
    await handleDutyAck(token, Number(RegExp.$1), { fromId, chatId, messageId, msgText }, callbackId);
    return;
  }

  const xm = /^x:(\d+):(approve|reject)$/.exec(data);
  if (xm) {
    await handleExpense(token, Number(xm[1]), xm[2], fromId, { chatId, messageId, msgText }, callbackId);
    return;
  }

  const tm = /^t:(\d+):(take|decline|p20|p50|p80|done)$/.exec(data);
  if (!tm) {
    await TelegramBot.answerCallbackQuery(token, callbackId);
    return;
  }
  await handleTask(token, Number(tm[1]), tm[2], fromId, { chatId, messageId, msgText }, callbackId);
}

/**
 * «Дежурство принял» — порт handleDutyAck.
 *
 * Принимает ТОЛЬКО текущий дежурный и ТОЛЬКО актуальную кнопку: позиция
 * ротации зашита в callback_data, чтобы вчерашняя кнопка не подтвердила
 * дежурство нового председателя.
 */
async function handleDutyAck(
  token: string,
  rotationIndex: number,
  msg: { fromId: number; chatId: string; messageId: number; msgText: string },
  callbackId: string,
): Promise<void> {
  const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
  const currentIndex = meeting?.rotationIndex ?? 0;

  if (rotationIndex !== currentIndex) {
    await TelegramBot.answerCallbackQuery(token, callbackId, 'Дежурство уже перешло дальше');
    if (token && msg.chatId && msg.messageId) {
      await TelegramBot.editMessageText(
        token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n⚠️ Это сообщение о прошлом дежурстве.'),
      );
    }
    return;
  }

  const member = msg.fromId
    ? await db.councilMember.findFirst({ where: { telegramId: BigInt(msg.fromId) } })
    : null;
  const name = member && member.status === 'active' ? member.name : '';
  if (name === '' || name !== (meeting?.dutyChair ?? '')) {
    await TelegramBot.answerCallbackQuery(
      token, callbackId, 'Подтвердить дежурство может только дежурный председатель',
    );
    return;
  }

  await db.councilMeeting.updateMany({ where: { id: 1 }, data: { dutyAckAt: new Date() } });
  await TelegramBot.answerCallbackQuery(token, callbackId, '✅ ' + DUTY_ACK_LABEL);
  if (token && msg.chatId && msg.messageId) {
    await TelegramBot.editMessageText(
      token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n✅ ' + DUTY_ACK_LABEL),
    );
  }
}

/** Кнопки казначея — порт handleExpense. Одобрять может только казначей. */
async function handleExpense(
  token: string,
  taskId: number,
  action: string,
  fromId: number,
  msg: { chatId: string; messageId: number; msgText: string },
  callbackId: string,
): Promise<void> {
  const member = fromId ? await db.councilMember.findFirst({ where: { telegramId: BigInt(fromId) } }) : null;
  const name = member && member.status === 'active' ? member.name : '';
  if (name === '' || name !== approverName()) {
    await TelegramBot.answerCallbackQuery(token, callbackId, 'Одобрять расходы может только казначей');
    return;
  }

  const task = await db.councilTask.findUnique({ where: { id: taskId } });
  if (!task) {
    await TelegramBot.answerCallbackQuery(token, callbackId, 'Задача не найдена');
    if (token && msg.chatId && msg.messageId) {
      await TelegramBot.editMessageText(
        token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n⚠️ Задача уже удалена.'),
      );
    }
    return;
  }

  if (action === 'approve') {
    await approveExpense(taskId);
    await TelegramBot.answerCallbackQuery(token, callbackId, '✅ Одобрено');
    if (token && msg.chatId && msg.messageId) {
      await TelegramBot.editMessageText(
        token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n✅ Одобрено'),
      );
    }
  } else {
    await rejectExpense(taskId);
    await TelegramBot.answerCallbackQuery(token, callbackId, '🚫 Отклонено');
    if (token && msg.chatId && msg.messageId) {
      await TelegramBot.editMessageText(
        token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n🚫 Отклонено'),
      );
    }
  }
}

/** Рабочие кнопки задачи — порт основной ветки handleCallback. */
async function handleTask(
  token: string,
  taskId: number,
  action: string,
  fromId: number,
  msg: { chatId: string; messageId: number; msgText: string },
  callbackId: string,
): Promise<void> {
  const tasks = db.councilTask;
  const task = await tasks.findUnique({ where: { id: taskId } });
  if (!task) {
    await TelegramBot.answerCallbackQuery(token, callbackId, 'Задача не найдена');
    if (token && msg.chatId && msg.messageId) {
      await TelegramBot.editMessageText(
        token, msg.chatId, msg.messageId, escapeHtml(msg.msgText + '\n\n⚠️ Задача уже удалена.'),
      );
    }
    return;
  }

  // Действие доступно только исполнителю.
  const member = fromId ? await db.councilMember.findFirst({ where: { telegramId: BigInt(fromId) } }) : null;
  const name = member && member.status === 'active' ? member.name : '';
  if (name === '' || name !== task.assignee) {
    await TelegramBot.answerCallbackQuery(token, callbackId, 'Эта задача назначена не вам');
    return;
  }

  const edit = (text: string, keyboard?: string) =>
    token && msg.chatId && msg.messageId
      ? TelegramBot.editMessageText(token, msg.chatId, msg.messageId, escapeHtml(text), 'HTML', keyboard)
      : Promise.resolve(null);

  switch (action) {
    case 'take':
      await tasks.update({ where: { id: taskId }, data: { status: 'в работе' } });
      await TelegramBot.answerCallbackQuery(token, callbackId, '✅ Взято в работу');
      await edit(msg.msgText + '\n\n✅ Вы взяли задачу в работу', progressKeyboard(taskId));
      return;

    case 'decline':
      await tasks.update({ where: { id: taskId }, data: { assignee: '', status: 'новая' } });
      await TelegramBot.answerCallbackQuery(token, callbackId, '🚫 Вы отказались');
      await edit(msg.msgText + '\n\n🚫 Вы отказались от задачи');
      return;

    case 'p20':
    case 'p50':
    case 'p80': {
      const pct = Number(action.slice(1));
      await tasks.update({ where: { id: taskId }, data: { progress: pct } });
      await TelegramBot.answerCallbackQuery(token, callbackId, `Прогресс: ${pct}%`);
      await edit(withStatusLine(msg.msgText, `Вы выполнили задачу на ${pct}%`), progressKeyboard(taskId));
      return;
    }

    case 'done':
      await tasks.update({
        where: { id: taskId },
        data: { status: 'выполнена', progress: 100, completedAt: new Date() },
      });
      await TelegramBot.answerCallbackQuery(token, callbackId, '🎉 Задача выполнена');
      await edit(withStatusLine(msg.msgText, '🎉 Вы выполнили эту задачу, большое спасибо!'));
      // Есть расход — казначею уйдёт запрос на одобрение (порт requestIfNeeded).
      // Есть расход — казначею уйдёт запрос на одобрение (порт requestIfNeeded).
      await requestExpenseIfNeeded(taskId);
      return;
  }
}

/** Клавиатура прогресса, появляется после «Взял в работу» (порт progressKeyboard). */
function progressKeyboard(id: number): string {
  return JSON.stringify({
    inline_keyboard: [
      [
        { text: '20%', callback_data: `t:${id}:p20` },
        { text: '50%', callback_data: `t:${id}:p50` },
        { text: '80%', callback_data: `t:${id}:p80` },
      ],
      [{ text: 'Выполнена', callback_data: `t:${id}:done` }],
    ],
  });
}

/**
 * Заменить строку статуса задачи на новую (порт withStatusLine): строки вида
 * «✅ Вы взяли задачу в работу» / «Вы выполнили задачу на N%» — служебные, их
 * переписываем; нет такой — дописываем в конец.
 */
export function withStatusLine(msgText: string, line: string): string {
  const re = /^(?:✅ Вы взяли задачу в работу|Вы выполнили задачу на \d+%)$/mu;
  return re.test(msgText) ? msgText.replace(re, line.replace(/\$/g, '$$$$')) : msgText + '\n\n' + line;
}

/** Сообщение о дежурстве, которое приходит в Telegram, переписывается HTML — экранируем. */
function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export { router as councilWebhookRoutes };
