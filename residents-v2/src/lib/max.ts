// Порт residents/src/MaxWebApp.php + MaxSubscription.php в TypeScript.
// Схема подписи initData у MAX совпадает с Telegram (dev.max.ru/docs/webapps/validation),
// отличие только в отсутствии поля signature — поэтому переиспользуем
// verifyInitData с allowSignatureVariant:false (единый канон: исключаем только hash).

import {
  verifyInitData,
  type InitDataUser,
  type GroupMembershipStatus,
} from './telegram';

const BASE = 'https://botapi.max.ru';
const FETCH_TIMEOUT_MS = 10000;

/** Порт MaxWebApp::verify: та же криптография, но без варианта со signature. */
export function verifyMaxInitData(initData: string, botToken: string): InitDataUser | null {
  return verifyInitData(initData, botToken, { allowSignatureVariant: false });
}

/**
 * Членство в группе жителей MAX: GET /chats/{chatId}/members?user_ids=…
 * с заголовком «Authorization: <token>». Возвращает {"members":[…]} — только
 * найденных участников; пусто → не состоит. Fail-closed (как легаси): любой
 * сбой/аномалия ответа → 'error', доступ не выдаётся.
 *
 * ВНИМАНИЕ: контракт members-эндпоинта статически не сверён с dev.max.ru
 * (SPA-документация) — реализовано по совместимому с TamTam образцу,
 * как и в MaxSubscription.php. Проверить вживую после добавления бота
 * в MAX-группу жителей.
 */
export async function maxChatMemberStatus(
  maxUserId: string,
  botToken: string,
  chatId: string,
): Promise<GroupMembershipStatus> {
  if (!maxUserId || !botToken || !chatId) return 'error';
  try {
    const url =
      BASE +
      '/chats/' +
      encodeURIComponent(chatId) +
      '/members?user_ids=' +
      encodeURIComponent(maxUserId);
    const res = await fetch(url, {
      headers: { Authorization: botToken },
      signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
    });
    if (!res.ok) return 'error';
    const data = (await res.json()) as { code?: unknown; members?: unknown };
    if (!data || data.code !== undefined || !Array.isArray(data.members)) return 'error';
    for (const m of data.members) {
      if (m && typeof m === 'object' && String((m as { user_id?: unknown }).user_id ?? '') === maxUserId) {
        return 'subscribed';
      }
    }
    return 'not_subscribed';
  } catch {
    return 'error';
  }
}

/**
 * Отправить личное сообщение пользователю MAX — порт MaxBot::sendMessage
 * (используется в рассылке членам совета — council-meeting-notify).
 *
 * POST /messages?user_id={id} с телом {"text": "…"}; авторизация — заголовок
 * «Authorization: <token>». Успех: пришёл объект отправленного сообщения без
 * error-кода (dialog.not.found, verify.token и т.п. → не ретраим). Сетевой
 * сбой — ретраим до 3 попыток, как в легаси (curl-сбой на сервере был терпим).
 */
export async function sendMaxMessage(botToken: string, userId: string, text: string): Promise<boolean> {
  if (!botToken || !userId || !text) return false;
  const url =
    BASE + '/messages?user_id=' + encodeURIComponent(userId) + '&disable_link_preview=true';

  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { Authorization: botToken, 'Content-Type': 'application/json' },
        body: JSON.stringify({ text }),
        signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
      });
      const data = (await res.json().catch(() => null)) as { code?: unknown; message?: unknown } | null;
      if (data && data.code === undefined && typeof data.message === 'string') return true;
      // Ответ есть, но с ошибкой — повтор не поможет, бот не поймёт причину.
      console.error(`sendMaxMessage: не доставлено ${userId}: ${JSON.stringify(data ?? res.status).slice(0, 200)}`);
      return false;
    } catch (err) {
      if (attempt === 3) {
        console.error('sendMaxMessage error:', err instanceof Error ? err.message : String(err));
        return false;
      }
      await new Promise((r) => setTimeout(r, 2000)); // пауза между попытками
    }
  }
  return false;
}
