/**
 * Уведомление нового Дежурного председателя — порт
 * residents/src/Service/CouncilDutyNotify.php.
 *
 * Отправляется в момент ротации (понедельник 21:00 МСК, cron).
 * Кнопка «Дежурство принял» имеет callback_data с позицией ротации,
 * чтобы кнопка из прошлой недели не подтвердила дежурство нового председателя.
 *
 * Токен читается в момент вызова (урок Vercel: module-level → undefined).
 */
import { TelegramBot } from './telegramBot';
import { db } from './db';

export const DUTY_ACK_LABEL = 'Дежурство принял';

/**
 * Текст сообщения. $withAckButton — добавить строку про кнопку (есть только в
 * Telegram, в MAX кнопок под сообщением не шлём).
 */
export function dutyNotifyText(chair: string, when: string, place: string, link: string, withAckButton: boolean = false): string {
  const first = chair.trim().split(/\s+/u)[0] ?? '';
  const lines: string[] = [
    (first ? first + ', ' : '') + 'Вы — дежурный председатель следующей встречи Попечительского совета',
    when ? when : 'Дата встречи уточняется',
  ];
  if (place) lines.push(place);
  lines.push('');
  lines.push('Обязанности Дежурного председателя смотрите в Положении о ПС.');
  lines.push('');
  if (withAckButton) {
    lines.push('Подтвердите принятие дежурства, нажав кнопку под сообщением.');
    lines.push('');
  }
  lines.push('Повестка встречи здесь:');
  lines.push(link);
  return lines.join('\n');
}

/** Кнопка «Дежурство принял» под сообщением. callback_data — позиция ротации. */
export function dutyAckKeyboard(rotationIndex: number): string {
  return JSON.stringify({
    inline_keyboard: [[{ text: '✅ ' + DUTY_ACK_LABEL, callback_data: `d:ack:${rotationIndex}` }]],
  });
}

/**
 * Отправить новому дежурному. Возвращает SentMessage или null (ошибка).
 * Вызывается из cron-функции ротации (dutyRotation.ts).
 */
export async function notifyNewChair(
  chair: string,
  when: string,
  place: string,
  rotationIndex: number,
): Promise<{ messageId: number; chatId: string } | null> {
  const botToken = process.env.SKAZKRAY_BOT_TOKEN || '';
  if (botToken === '') {
    console.error(`DutyNotify: нет bot token`);
    return null;
  }

  const base = (process.env.BASE_URL || 'https://skaz-kray.ru').replace(/\/+$/, '');
  const link = base + '/sovet';

  const member = await db.councilMember.findFirst({
    where: { name: chair },
    select: { telegramId: true },
  });
  if (!member?.telegramId) {
    console.error(`DutyNotify: нет telegram_id у "${chair}"`);
    return null;
  }

  const text = dutyNotifyText(chair, when, place, link, true);
  const keyboard = dutyAckKeyboard(rotationIndex);

  const sent = await TelegramBot.sendMessage(botToken, String(member.telegramId), text, {
    replyMarkup: keyboard,
  });
  if (!sent) {
    console.error(`DutyNotify: не доставлено — ${chair}`);
    return null;
  }
  console.log(`DutyNotify: отправлено "${chair}" (msg_id=${sent.messageId})`);
  return sent;
}
