import { Router, Request, Response } from 'express';
import { db } from '../../lib/db';
import { advance } from '../../lib/dutyRotation';
import { runMeetingNotify } from '../../lib/meetingNotify';
import { formatMeetingDate } from './meeting';

/**
 * Vercel Cron раздела Совета — порты двух PHP-скриптов:
 *  - residents/bin/council-meeting-advance.php (понедельник 21:00 МСК) — ротация;
 *  - residents/bin/council-meeting-notify.php (ежедневно 06:00 UTC = 09:00 МСК) —
 *    рассылка членам совета о сегодняшней встрече (см. /cron/meeting-notify).
 *
 * Расписание задано в vercel.json (crons): понедельник 18:00 UTC = 21:00 МСК.
 * Vercel шлёт CRON_SECRET в Authorization: Bearer — им же защищён мануальный
 * запуск (иначе любой мог бы крутить ротацию, хоть она и проверяет день).
 *
 * Логика как в PHP-скрипте:
 *  - встреча должна быть сегодня (понедельник) — иначе ротацию НЕ двигаем
 *    (перенесённая встреча остаётся за тем же дежурным);
 *  - --force/--dry-run эквиваленты: ?force=1 / ?dry_run=1 с тем же секретом.
 */

const router = Router();

/** Проверка секрета Vercel Cron (CRON_SECRET) или явный force-запуск. */
function authorized(req: Request): boolean {
  const secret = process.env.CRON_SECRET || '';
  if (secret === '') return false; // секрет не задан — не пускаем
  const auth = String(req.headers.authorization ?? '');
  return auth === `Bearer ${secret}`;
}

/**
 * GET /api/council/cron/duty-advance
 * (POST тоже принимаем — на случай ручного вызова из инструментов)
 */
async function handler(req: Request, res: Response): Promise<void> {
  if (!authorized(req)) {
    res.status(403).json({ error: 'Forbidden' });
    return;
  }

  try {
    const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
    const startsAt = meeting?.startsAt;
    if (!startsAt) {
      res.json({ ok: true, skipped: 'нет даты встречи' });
      return;
    }

    const force = 'force' in req.query || 'force' in (req.body ?? {});
    const dryRun = 'dry_run' in req.query || 'dry_run' in (req.body ?? {});

    // Сегодняшний день по Москве — сервер Vercel живёт в UTC.
    const nowMsk = new Date(Date.now() + 3 * 3600 * 1000);
    const todayMsk = nowMsk.toISOString().slice(0, 10);
    const dow = nowMsk.getUTCDay(); // 0=Вс … 1=Пн
    const meetingDay = startsAt.toISOString().slice(0, 10);

    if (!force) {
      if (dow !== 1) {
        res.json({ ok: true, skipped: `сегодня не понедельник (${todayMsk})` });
        return;
      }
      if (meetingDay !== todayMsk) {
        res.json({
          ok: true,
          skipped: `встреча не сегодня (назначена на ${meetingDay}) — вероятно перенесена`,
        });
        return;
      }
    }

    if (dryRun) {
      const next = new Date(startsAt.getTime() + 7 * 24 * 3600 * 1000);
      const { dutyNameForIndex } = await import('../../lib/councilData');
      const chair = dutyNameForIndex((meeting?.rotationIndex ?? 0) + 1);
      res.json({
        ok: true,
        dryRun: true,
        nextChair: chair,
        nextMeeting: formatMeetingDate(next),
      });
      return;
    }

    const r = await advance();
    if (!r) {
      res.status(500).json({ error: 'advance вернул null' });
      return;
    }
    res.json({ ok: true, ...r });
  } catch (err) {
    console.error('Cron duty-advance error:', err);
    res.status(500).json({ error: 'Ошибка ротации' });
  }
}

router.get('/cron/duty-advance', handler);
router.post('/cron/duty-advance', handler);

/**
 * GET /api/council/cron/meeting-notify
 * (POST тоже принимаем — на случай ручного вызова из инструментов)
 *
 * Ежедневная рассылка о встрече — порт council-meeting-notify.php.
 * Секрет тот же (CRON_SECRET). Эквиваленты флагов: ?force=1 / ?dry_run=1.
 */
async function notifyHandler(req: Request, res: Response): Promise<void> {
  if (!authorized(req)) {
    res.status(403).json({ error: 'Forbidden' });
    return;
  }

  try {
    const force = 'force' in req.query || 'force' in (req.body ?? {});
    const dryRun = 'dry_run' in req.query || 'dry_run' in (req.body ?? {});
    const r = await runMeetingNotify({ force, dryRun });
    res.json(r);
  } catch (err) {
    console.error('Cron meeting-notify error:', err);
    res.status(500).json({ error: 'Ошибка рассылки' });
  }
}

router.get('/cron/meeting-notify', notifyHandler);
router.post('/cron/meeting-notify', notifyHandler);

/**
 * GET  /api/council/cron/webhook       — getWebhookInfo (куда шлёт апдейты бот);
 * POST /api/council/cron/webhook?set=1  — перерегистрировать webhook на BASE_URL.
 *
 * Порт scripts/set-webhook.sh. Скрипт недоступен с рабочей машины (Telegram API
 * блокируется, а старый прокси мёртв), поэтому управление ушло внутрь серверless
 * функции — там сеть до api.telegram.org открыта (тот же канал, что у отправки
 * уведомлений). Секрет — CRON_SECRET, как у cron-роутов выше.
 *
 * Важно: webhook обязан указывать на URL без редиректа — Telegram не следует
 * за 308 (skaz-kray.ru → www.skaz-kray.ru), и апдейты теряются молча.
 */
async function webhookHandler(req: Request, res: Response): Promise<void> {
  if (!authorized(req)) {
    res.status(403).json({ error: 'Forbidden' });
    return;
  }

  const token = process.env.SKAZKRAY_BOT_TOKEN || '';
  if (token === '') {
    res.status(500).json({ error: 'SKAZKRAY_BOT_TOKEN не задан' });
    return;
  }

  const base = (process.env.BASE_URL || 'https://skaz-kray.ru').replace(/\/+$/, '');
  // URL можно перекрыть явно: BASE_URL держит канонический домен для ссылок
  // пользователям, а webhook обязан сидеть на домене БЕЗ редиректа (иначе
  // Telegram отбрасывает апдейты — 308 на www не преследуется).
  const raw = typeof req.query.url === 'string' ? req.query.url : '';
  const override = /^https:\/\/[\w.-]+\/[^\s]*$/.test(raw) ? raw : '';
  const hook = override || base + '/api/council/webhook/';
  const call = async (method: string, body?: Record<string, unknown>): Promise<unknown> => {
    const r = await fetch(`https://api.telegram.org/bot${token}/${method}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body ?? {}),
      signal: AbortSignal.timeout(15000),
    });
    return (await r.json().catch(() => null)) as unknown;
  };

  try {
    const info = await call('getWebhookInfo');
    if (!('set' in req.query)) {
      res.json({ hook, info });
      return;
    }

    const secret = process.env.SKAZKRAY_WEBHOOK_SECRET || '';
    if (secret === '') {
      res.status(500).json({ error: 'SKAZKRAY_WEBHOOK_SECRET не задан' });
      return;
    }
    const set = await call('setWebhook', {
      url: hook,
      secret_token: secret,
      allowed_updates: ['callback_query'],
      drop_pending_updates: true,
    });
    const after = await call('getWebhookInfo');
    res.json({ hook, set, after });
  } catch (err) {
    console.error('Cron webhook error:', err);
    res.status(500).json({ error: 'Ошибка работы с Bot API' });
  }
}

router.get('/cron/webhook', webhookHandler);
router.post('/cron/webhook', webhookHandler);

export { router as councilCronRoutes };