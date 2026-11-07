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
