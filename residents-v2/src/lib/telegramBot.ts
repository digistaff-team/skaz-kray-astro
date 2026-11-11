/**
 * Минимальный клиент Telegram Bot API — порт residents/src/TelegramBot.php.
 *
 * Нужен для исходящих сообщений раздела Совета: уведомление новому Дежурному
 * председателю (с inline-кнопкой), запросы казначею, ответы на нажатия кнопок.
 *
 * Токен передаётся в каждый вызов и читается вызывающим в момент вызова: на
 * Vercel module-level чтение process.env даёт undefined (модуль инициализируется
 * до подстановки переменных окружения функции).
 */

export type ParseMode = 'HTML' | 'Markdown' | 'MarkdownV2';

export interface SendOptions {
  parseMode?: ParseMode;
  /** Готовая JSON-строка с reply_markup (inline_keyboard). */
  replyMarkup?: string;
  /** true — без звукового уведомления. */
  silent?: boolean;
}

export interface SentMessage {
  messageId: number;
  chatId: string;
}

const API = 'https://api.telegram.org';
const TIMEOUT_MS = 10_000;

/** Один POST в Bot API. Возвращает result или null (ошибка логируется). */
async function call(token: string, method: string, params: Record<string, unknown>): Promise<unknown> {
  if (token === '') return null;
  try {
    const res = await fetch(`${API}/bot${token}/${method}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(params),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    const json = (await res.json().catch(() => null)) as
      | { ok?: boolean; result?: unknown; description?: string }
      | null;
    if (!res.ok || !json?.ok) {
      console.error(`TelegramBot.${method}: ${res.status} ${json?.description ?? ''}`.trim());
      return null;
    }
    return json.result ?? null;
  } catch (err) {
    console.error(`TelegramBot.${method} error:`, err instanceof Error ? err.message : String(err));
    return null;
  }
}

export const TelegramBot = {
  /**
   * Отправить сообщение. Возвращает messageId — он нужен, чтобы потом убрать
   * кнопки через editMessageText (как TelegramBot::sendMessage в легаси).
   */
  async sendMessage(
    token: string,
    chatId: string,
    text: string,
    opts: SendOptions = {},
  ): Promise<SentMessage | null> {
    if (chatId === '' || text === '') return null;
    const params: Record<string, unknown> = {
      chat_id: chatId,
      text,
      disable_web_page_preview: true,
    };
    if (opts.parseMode) params.parse_mode = opts.parseMode;
    if (opts.replyMarkup) params.reply_markup = opts.replyMarkup;
    if (opts.silent) params.disable_notification = true;

    const result = (await call(token, 'sendMessage', params)) as
      | { message_id?: number; chat?: { id?: number | string } }
      | null;
    if (!result?.message_id) return null;
    return { messageId: result.message_id, chatId: String(result.chat?.id ?? chatId) };
  },

  /**
   * Переписать сообщение. replyMarkup не передан — кнопки снимаются (пустая
   * клавиатура), это нужно после нажатия кнопки: повторное нажатие бессмысленно.
   */
  async editMessageText(
    token: string,
    chatId: string,
    messageId: number,
    text: string,
    parseMode: ParseMode = 'HTML',
    replyMarkup?: string,
  ): Promise<boolean> {
    if (chatId === '' || !messageId) return false;
    const params: Record<string, unknown> = {
      chat_id: chatId,
      message_id: messageId,
      text,
      parse_mode: parseMode,
    };
    // Пустая inline-клавиатура снимает прежние кнопки.
    params.reply_markup = replyMarkup ?? { inline_keyboard: [] };
    return (await call(token, 'editMessageText', params)) !== null;
  },

  /**
   * Ответ на callback_query — обязателен, иначе у клиента крутится часы на кнопке.
   * text показывает toast (не более 200 символов).
   */
  async answerCallbackQuery(token: string, callbackId: string, text?: string): Promise<boolean> {
    if (callbackId === '') return false;
    const params: Record<string, unknown> = { callback_query_id: callbackId };
    if (text) params.text = text.slice(0, 200);
    return (await call(token, 'answerCallbackQuery', params)) !== null;
  },
};
