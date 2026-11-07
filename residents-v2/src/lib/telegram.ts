// Порт residents/src/TelegramWebApp.php + TelegramSubscription.php +
// TgAuthController::decodeStartParam в TypeScript.
// https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
//
// Секрет = HMAC_SHA256(key="WebAppData", msg=botToken); подпись = HMAC_SHA256(
// key=secret, msg=data_check_string) — все поля кроме hash, url-decoded,
// отсортированные, «k=v» через \n. Поле user — URL-decoded JSON.
//
// Секреты/env НЕ читаются здесь на уровне модуля — botToken/chatId приходят
// аргументами, роуты берут process.env в момент вызова (урок Vercel:
// module-level `!` → undefined на серверлесс).

import crypto from 'crypto';

export interface InitDataUser {
  /** id из поля user — всегда строка (как в легаси-PHP). */
  id: string;
  firstName: string | null;
  lastName: string | null;
  username: string | null;
}

export interface VerifyInitDataOptions {
  /**
   * Telegram: часть клиентов считает подпись без поля signature (Ed25519) —
   * принимаем обе трактовки. Канонический вариант (исключая только hash)
   * проверяется всегда; этот флаг добавляет второй, исключающий signature.
   * У MAX поля signature нет — флаг не нужен (см. lib/max.ts).
   */
  allowSignatureVariant?: boolean;
}

export type GroupMembershipStatus = 'subscribed' | 'not_subscribed' | 'error';

const MAX_AUTH_AGE = 86400; // 24 часа
const CLOCK_SKEW = 300; // допуск на рассинхрон часов, 5 мин
const FETCH_TIMEOUT_MS = 10000;

/** PHP urldecode: «+» — пробел, затем percent-decode (не URLSearchParams). */
function urlDecode(raw: string): string {
  return decodeURIComponent(raw.replace(/\+/g, '%20'));
}

/**
 * Ручной разбор query-строки (как в легаси): parse_str/URLSearchParams манглят
 * «+» в пробел ДО того, как оно попадает в HMAC, и ломают подпись.
 * Ключ без «=» пропускается, дубли — последний побеждает (как в PHP).
 * Битые percent-последовательности → null (fail-closed).
 */
function parseInitData(initData: string): Map<string, string> | null {
  const params = new Map<string, string>();
  for (const chunk of initData.split('&')) {
    if (chunk === '') continue;
    const eq = chunk.indexOf('=');
    if (eq === -1) continue;
    let key: string;
    let value: string;
    try {
      key = urlDecode(chunk.slice(0, eq));
      value = urlDecode(chunk.slice(eq + 1));
    } catch {
      return null;
    }
    params.set(key, value);
  }
  return params;
}

/**
 * data_check_string: пары «k=v» (кроме hash, опционально — кроме signature),
 * отсортированные и склеенные «\n»; сравнение через timingSafeEqual.
 */
function hashMatches(
  params: Map<string, string>,
  secret: Buffer,
  hash: string,
  excludeSignature: boolean,
): boolean {
  const pairs: string[] = [];
  for (const [k, v] of params) {
    if (k === 'hash') continue;
    if (excludeSignature && k === 'signature') continue;
    pairs.push(k + '=' + v);
  }
  pairs.sort();
  const computed = crypto.createHmac('sha256', secret).update(pairs.join('\n')).digest('hex');
  if (hash.length !== computed.length) return false;
  return crypto.timingSafeEqual(Buffer.from(computed, 'utf8'), Buffer.from(hash, 'utf8'));
}

/**
 * Проверяет подпись initData и свежесть auth_date.
 * Возвращает данные пользователя либо null (подделка/просрочка/пусто).
 */
export function verifyInitData(
  initData: string,
  botToken: string,
  opts?: VerifyInitDataOptions,
): InitDataUser | null {
  if (!initData || !botToken) return null;

  const params = parseInitData(initData);
  if (!params) return null;

  const hash = params.get('hash');
  if (!hash) return null;

  const secret = crypto.createHmac('sha256', 'WebAppData').update(botToken).digest(); // binary
  const matched =
    hashMatches(params, secret, hash, false) ||
    (opts?.allowSignatureVariant === true && hashMatches(params, secret, hash, true));
  if (!matched) return null;

  const authDate = Number.parseInt(params.get('auth_date') ?? '', 10) || 0;
  const age = Math.floor(Date.now() / 1000) - authDate;
  if (authDate <= 0 || age < -CLOCK_SKEW || age > MAX_AUTH_AGE) return null;

  const userRaw = params.get('user') ?? '';
  let u: unknown;
  try {
    u = JSON.parse(userRaw);
  } catch {
    return null;
  }
  if (!u || typeof u !== 'object') return null;
  const rec = u as Record<string, unknown>;
  if (rec.id === undefined || rec.id === null) return null;
  const id = typeof rec.id === 'string' || typeof rec.id === 'number' ? String(rec.id) : '';
  if (!/^\d+$/.test(id)) return null; // не-числовой id — значит, это не Telegram

  return {
    id,
    firstName: typeof rec.first_name === 'string' ? rec.first_name : null,
    lastName: typeof rec.last_name === 'string' ? rec.last_name : null,
    username: typeof rec.username === 'string' && rec.username !== '' ? rec.username : null,
  };
}

/**
 * Членство в Telegram-группе жителей через Bot API getChatMember.
 * Fail-closed (как легаси): любой сбой/отсутствие env → 'error', доступ не
 * выдаётся — это основной вход в закрытый портал.
 */
export async function chatMemberStatus(
  telegramId: string,
  botToken: string,
  chatId: string,
): Promise<GroupMembershipStatus> {
  if (!telegramId || !botToken || !chatId) return 'error';
  try {
    const url =
      'https://api.telegram.org/bot' +
      botToken +
      '/getChatMember?chat_id=' +
      encodeURIComponent(chatId) +
      '&user_id=' +
      encodeURIComponent(telegramId);
    const res = await fetch(url, { signal: AbortSignal.timeout(FETCH_TIMEOUT_MS) });
    if (!res.ok) return 'error';
    const data = (await res.json()) as { ok?: boolean; result?: { status?: string } };
    if (!data || data.ok !== true || !data.result || typeof data.result.status !== 'string') {
      return 'error';
    }
    const subscribed = ['member', 'administrator', 'creator'];
    return subscribed.includes(data.result.status) ? 'subscribed' : 'not_subscribed';
  } catch {
    return 'error';
  }
}

/**
 * base64url(путь) → безопасный внутренний путь портала, иначе fallback.
 * Защита от open-redirect: путь обязан начинаться с «/poselenie/».
 * Легаси-фолбэк «/poselenie/app» здесь заменён на «/poselenie/» — app в v2 нет.
 */
export function decodeStartParam(
  startParam: string | null | undefined,
  fallback = '/poselenie/',
): string {
  if (!startParam) return fallback;
  if (!/^[A-Za-z0-9\-_]+={0,2}$/.test(startParam)) return fallback; // мусор не base64url
  try {
    const b64 = startParam.replace(/-/g, '+').replace(/_/g, '/');
    const pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4));
    const decoded = Buffer.from(b64 + pad, 'base64').toString('utf8');
    if (!decoded.startsWith('/poselenie/') || decoded.includes('\uFFFD')) return fallback;
    return decoded;
  } catch {
    return fallback;
  }
}
