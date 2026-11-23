/**
 * Ежедневная рассылка членам совета о встрече — порт
 * residents/bin/council-meeting-notify.php (cron 06:00 UTC = 09:00 МСК).
 *
 * Шлём всем активным членам совета с привязанным Telegram и/или MAX: дату,
 * место, дежурных, ссылку на повестку (диплинк свой для каждой платформы).
 * Идемпотентность: пометка notified_for = день встречи (за день — одна
 * рассылка). Каналы независимы: привязавший обе платформы получит в обе.
 *
 * Как и в легаси, перед рассылкой синхронизируем дежурного председателя по
 * графику ротации — карточка и уведомление должны называть актуального.
 *
 * Токены/env читаются в момент вызова (урок Vercel: module-level → undefined).
 */
import { db } from './db';
import { TelegramBot } from './telegramBot';
import { sendMaxMessage } from './max';
import { applyDutyRotation } from './councilDuty';
import { councilAppLink } from './councilLinks';

/** Текст уведомления — порт $makeText из council-meeting-notify.php. */
export function meetingNotifyText(
  meetingDate: string,
  place: string,
  dutyChair: string,
  dutySecretary: string,
  link: string,
): string {
  return [
    '🗓 Сегодня встреча Попечительского совета',
    '',
    meetingDate,
    place,
    '',
    'Дежурный председатель: ' + dutyChair,
    'Дежурный секретарь: ' + dutySecretary,
    '',
    'Повестка встречи в приложении:',
    link,
  ].join('\n');
}

/** Экранирование HTML в интерполируемых полях (parse_mode: 'HTML'). */
function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * HTML-вариант для Telegram: слова «Повестка встречи в приложении» —
 * гиперссылка на диплинк мини-приложения (parse_mode: 'HTML').
 */
export function meetingNotifyTextHtml(
  meetingDate: string,
  place: string,
  dutyChair: string,
  dutySecretary: string,
  link: string,
): string {
  return [
    '🗓 Сегодня встреча Попечительского совета',
    '',
    esc(meetingDate),
    esc(place),
    '',
    'Дежурный председатель: ' + esc(dutyChair),
    'Дежурный секретарь: ' + esc(dutySecretary),
    '',
    `<a href="${esc(link)}">Повестка встречи в приложении</a>`,
  ].join('\n');
}

export interface NotifyRecipients {
  tg: Array<{ id: number; name: string; telegramId: bigint }>;
  max: Array<{ id: number; name: string; maxUserId: bigint }>;
}

/** Получатели: активные члены совета с привязанным Telegram и/или MAX. */
export async function notifyRecipients(): Promise<NotifyRecipients> {
  const all = await db.councilMember.findMany({
    where: { status: 'active' },
    orderBy: { id: 'asc' },
    select: { id: true, name: true, telegramId: true, maxUserId: true },
  });
  return {
    tg: all.filter((m) => m.telegramId !== null)
      .map((m) => ({ id: m.id, name: m.name, telegramId: m.telegramId! })),
    max: all.filter((m) => m.maxUserId !== null)
      .map((m) => ({ id: m.id, name: m.name, maxUserId: m.maxUserId! })),
  };
}

export interface MeetingNotifyResult {
  ok: boolean;
  skipped?: string;
  dryRun?: boolean;
  meetingDay?: string;
  textTg?: string;
  textMax?: string;
  recipients?: { tg: string[]; max: string[] };
  sent?: number;
  failed?: number;
  dutyChair?: string | null;
}

/**
 * Прогон рассылки. force — игнорировать проверку даты/повтора (ручной тест),
 * dryRun — показать текст и получателей, ничего не отправляя.
 */
export async function runMeetingNotify(
  opts: { force?: boolean; dryRun?: boolean } = {},
): Promise<MeetingNotifyResult> {
  // Синхронизация дежурного по графику — как в PHP в начале скрипта.
  const dutyChair = await applyDutyRotation();

  const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
  if (!meeting) return { ok: true, skipped: 'council_meeting пуст — нечего рассылать', dutyChair };

  // День встречи по Москве (starts_at хранится naive = московское стенное время).
  const meetingDay = meeting.startsAt ? meeting.startsAt.toISOString().slice(0, 10) : '';
  const nowMsk = new Date(Date.now() + 3 * 3600 * 1000).toISOString().slice(0, 10);

  if (!opts.force) {
    if (meetingDay === '' || meetingDay !== nowMsk) {
      return {
        ok: true,
        skipped: `сегодня (${nowMsk}) не день встречи (встреча: ${meetingDay || 'не задана'})`,
        dutyChair,
      };
    }
    if (meeting.notifiedFor && meeting.notifiedFor.toISOString().slice(0, 10) === nowMsk) {
      return { ok: true, skipped: `за ${nowMsk} уже рассылали`, dutyChair };
    }
  }

  const recipients = await notifyRecipients();
  const textMax = meetingNotifyText(
    meeting.meetingDate, meeting.place, meeting.dutyChair, meeting.dutySecretary, councilAppLink('max'),
  );
  // В Telegram «Повестка встречи в приложении» — гиперссылка (parse_mode: HTML);
  // MAX разметку не поддерживает — там обычный текст со ссылкой отдельной строкой.
  const textTg = meetingNotifyTextHtml(
    meeting.meetingDate, meeting.place, meeting.dutyChair, meeting.dutySecretary, councilAppLink('tg'),
  );

  if (opts.dryRun) {
    return {
      ok: true,
      dryRun: true,
      meetingDay,
      textTg,
      textMax,
      recipients: { tg: recipients.tg.map((r) => r.name), max: recipients.max.map((r) => r.name) },
      dutyChair,
    };
  }

  const tgToken = process.env.SKAZKRAY_BOT_TOKEN || '';
  const maxToken = process.env.SKAZKRAY_MAX_BOT_TOKEN || '';
  let sent = 0;
  let failed = 0;

  for (const r of recipients.tg) {
    if (tgToken === '') break;
    const ok = (await TelegramBot.sendMessage(tgToken, String(r.telegramId), textTg, {
      parseMode: 'HTML',
    })) !== null;
    if (ok) sent++;
    else {
      failed++;
      console.error(`meetingNotify: Telegram не доставлено: ${r.name}`);
    }
  }
  for (const r of recipients.max) {
    if (maxToken === '') break;
    const ok = await sendMaxMessage(maxToken, String(r.maxUserId), textMax);
    if (ok) sent++;
    else {
      failed++;
      console.error(`meetingNotify: MAX не доставлено: ${r.name}`);
    }
  }

  // Помечаем как разосланное за этот день (идемпотентность, как notified_for в легаси).
  if (meetingDay !== '') {
    await db.councilMeeting.update({
      where: { id: 1 },
      data: { notifiedFor: new Date(meetingDay + 'T00:00:00.000Z') },
    });
  }

  console.log(`meetingNotify: готово — отправлено ${sent}, ошибок ${failed}`);
  return { ok: true, meetingDay, sent, failed, dutyChair };
}