import { db } from './db';

/**
 * Личное сообщение семье в Telegram — порт BotNotify::toFamily (только Telegram,
 * без MAX и журнала исходящих). Fail-closed: любой сбой (нет env, нет сети,
 * бот не может писать) логируется и НЕ роняет запрос, который вызвал
 * уведомление — дублирующее действие (заявка и т.п.) уже выполнено.
 * Токен/env читаются в момент вызова (урок Vercel: module-level → undefined).
 */
export async function notifyFamily(
  familyId: number,
  lines: string[],
  path?: string,
): Promise<void> {
  try {
    const token = process.env.SKAZKRAY_BOT_TOKEN || '';
    if (!token || lines.length === 0) return;

    const family = await db.family.findUnique({
      where: { id: familyId },
      select: { telegramId: true },
    });
    if (!family?.telegramId) return;

    let text = lines.join('\n\n');
    if (path) {
      const base = (process.env.BASE_URL || 'https://skaz-kray.ru').replace(/\/+$/, '');
      text += '\n\nОткрыть: ' + base + path;
    }

    const res = await fetch('https://api.telegram.org/bot' + token + '/sendMessage', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: family.telegramId.toString(), text }),
      signal: AbortSignal.timeout(10000),
    });
    if (!res.ok) {
      console.error('notifyFamily: Bot API ответил', res.status, 'для семьи', familyId);
    }
  } catch (err) {
    console.error(
      'notifyFamily error:',
      err instanceof Error ? err.name + ': ' + err.message : String(err),
    );
  }
}
