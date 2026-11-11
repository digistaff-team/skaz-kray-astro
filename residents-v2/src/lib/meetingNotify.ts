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

/** base64url(путь) без padding — порт BotNotify::deepLink. */
function deepLink(base: string, path: string): string {
  const code = Buffer.from(path, 'utf8').toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
  // MAX отдаёт base с уже вшитым '?startapp' — параметр дописываем через '='.
  return base.endsWith('?startapp')
    ? base + '=' + code
    : base.replace(/\/+$/, '') + '?startapp=' + code;
}

/** Диплинк мини-приложения Совета: Telegram и MAX (порты CouncilDutyNotify::link/base). */
function tgLink(): string {
  const base = process.env.COUNCIL_APP_LINK || 'https://t.me/SkazKray_bot/sovet';
  return deepLink(base, '/sovet');
}

function maxLink(): string {
  const base = process.env.COUNCIL_MAX_APP_LINK || 'https://max.ru/id643900558807_2_bot?startapp';
  return deepLink(base, '/sovet');
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
  const textTg = meetingNotifyText(
    meeting.meetingDate, meeting.place, meeting.dutyChair, meeting.dutySecretary, tgLink(),
  );
  const textMax = meetingNotifyText(
    meeting.meetingDate, meeting.place, meeting.dutyChair, meeting.dutySecretary, maxLink(),
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
    const ok = await TelegramBot.sendMessage(tgToken, String(r.telegramId), textTg);
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