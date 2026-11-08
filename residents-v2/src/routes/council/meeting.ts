import { Router } from 'express';
import { AuthRequest, requireCouncil } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError, isCouncilEditor, councilName } from '../../lib/councilHelpers';
import { applyDutyRotation, setDutyChair } from '../../lib/councilDuty';
import { defaultMeeting } from '../../lib/councilData';

const router = Router();

/**
 * Карточка «Ближайшее собрание» и повестка — порт
 * residents/src/Controller/Council/MeetingController.php + AgendaController.php.
 *
 * Править встречу и передавать дежурство может текущий Дежурный председатель
 * (флаг is_duty_chair) ИЛИ администратор совета: роль дежурного переходящая.
 */

// ─────────────────────────── Встреча ───────────────────────────

/** GET /api/council/meeting — карточка ближайшего собрания (+ черновик даты для формы). */
router.get('/meeting', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
    const canEdit = await isCouncilEditor(req.council!);

    // Дата не выбрана или встреча в прошлом → предлагаем ближайший будущий
    // понедельник (UTC+3): сегодня-понедельник → сегодня, иначе следующий.
    let startsAt = meeting?.startsAt ?? null;
    if (!startsAt || isPastDay(startsAt)) {
      startsAt = upcomingMonday('18:00');
    }

    res.json({
      meeting: meeting
        ? {
            meetingDate: meeting.meetingDate,
            startsAt: startsAt.toISOString(),
            endsAt: meeting.endsAt ? meeting.endsAt.toISOString() : null,
            place: meeting.place,
            dutyChair: meeting.dutyChair,
            dutySecretary: meeting.dutySecretary,
            rotationIndex: meeting.rotationIndex,
          }
        : { ...defaultMeeting(), startsAt: startsAt.toISOString(), endsAt: null, rotationIndex: 0 },
      canEdit,
    });
  } catch (err) {
    console.error('Council meeting error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** День встречи уже прошёл (по календарю Москвы)? */
function isPastDay(d: Date): boolean {
  const msk = new Date(d.toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
  const today = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
  return msk.toISOString().slice(0, 10) < today.toISOString().slice(0, 10);
}

/** Ближайший будущий понедельник (UTC+3) — как upcomingMonday в легаси. */
function upcomingMonday(time: string): Date {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
  const dow = now.getDay(); // 0=Вс … 1=Пн
  const add = dow === 1 ? 0 : (8 - dow) % 7 || 7;
  const [h, m] = time.split(':').map(Number);
  const d = new Date(now);
  if (add > 0) d.setDate(d.getDate() + add);
  d.setHours(h, m, 0, 0);
  return d;
}

/**
 * POST /api/council/meeting — сохранить карточку (порт MeetingController::save).
 * Дежурный председатель назначается автоматически по графику ротации —
 * подпись в карточке синхронизирует applyDutyRotation.
 */
router.post('/meeting', requireCouncil, async (req: AuthRequest, res) => {
  try {
    if (!(await isCouncilEditor(req.council!))) {
      return httpError(res, 403, 'Редактировать встречу может только Дежурный председатель или администратор совета.');
    }

    const body = (req.body ?? {}) as Record<string, unknown>;
    const startsRaw = String(body.starts_at ?? '').trim();
    const place = String(body.place ?? '').trim();
    const dutySecretary = String(body.duty_secretary ?? '').trim();

    const startsAt = startsRaw !== '' ? new Date(startsRaw) : null;
    const errors: Record<string, string> = {};
    if (!startsAt || Number.isNaN(startsAt.getTime())) errors.starts_at = 'Укажите дату и время.';
    if (place.length < 2 || place.length > 200) errors.place = 'Место: 2–200 символов.';
    if (dutySecretary !== '' && (dutySecretary.length < 2 || dutySecretary.length > 160)) {
      errors.duty_secretary = 'До 160 символов.';
    }
    if (Object.keys(errors).length > 0) {
      return res.status(400).json({ error: 'Проверьте поля формы.', fields: errors });
    }

    // Подпись дежурного не из формы: её ставит ротация.
    const current = await db.councilMeeting.findUnique({ where: { id: 1 } });
    const dutyChair = current?.dutyChair ?? '';

    await db.councilMeeting.upsert({
      where: { id: 1 },
      create: {
        id: 1,
        startsAt,
        place,
        dutyChair,
        dutySecretary,
        meetingDate: formatMeetingDate(startsAt!),
      },
      update: {
        startsAt,
        place,
        dutySecretary,
        meetingDate: formatMeetingDate(startsAt!),
      },
    });

    await applyDutyRotation();

    const fresh = await db.councilMeeting.findUnique({ where: { id: 1 } });
    res.json({ ok: true, meeting: fresh });
  } catch (err) {
    console.error('Council meeting save error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** «7 сентября 2026, 18:00–20:00» — подпись карточки (легаси meetingDate). */
function formatMeetingDate(startsAt: Date): string {
  const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
    'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  const msk = new Date(startsAt.toLocaleString('en-US', { timeZone: 'Europe/Moscow' }));
  const hh = String(msk.getHours()).padStart(2, '0');
  const mm = String(msk.getMinutes()).padStart(2, '0');
  return `${msk.getDate()} ${months[msk.getMonth()]} ${msk.getFullYear()}, ${hh}:${mm}`;
}

/**
 * POST /api/council/duty/handoff — передать дежурство другому члену совета
 * (порт MeetingController::handoff). Передавать можно только активному члену
 * совета, не админу; подпись в карточке собрания держим в синхроне.
 */
router.post('/duty/handoff', requireCouncil, async (req: AuthRequest, res) => {
  try {
    if (!(await isCouncilEditor(req.council!))) {
      return httpError(res, 403, 'Передать дежурство может только Дежурный председатель или администратор совета.');
    }
    const newId = Number(req.body?.member_id ?? 0);
    const member = newId > 0
      ? await db.councilMember.findUnique({ where: { id: newId } })
      : null;

    if (!member) return httpError(res, 400, 'Выберите члена совета для передачи дежурства.');
    if (member.status !== 'active' || member.role === 'admin') {
      return httpError(res, 400, 'Передать дежурство можно только активному члену совета.');
    }

    await setDutyChair(member.id);
    await db.councilMeeting.update({
      where: { id: 1 },
      data: { dutyChair: member.name },
    }).catch(() => undefined); // строки может ещё не быть — подпись проставит ротация

    res.json({ ok: true, dutyChair: member.name });
  } catch (err) {
    console.error('Council duty handoff error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// ─────────────────────────── Повестка ───────────────────────────

/**
 * GET /api/council/agenda — пункты повестки.
 * Пункт может добавить любой член совета (автор фиксируется). Удалять — автор
 * пункта или дежурный/админ; сортировать и отмечать «обсуждено» — дежурный/админ.
 */
router.get('/agenda', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const [items, meeting] = await Promise.all([
      db.councilAgendaItem.findMany({ orderBy: [{ sort: 'asc' }, { id: 'asc' }] }),
      db.councilMeeting.findUnique({ where: { id: 1 } }),
    ]);
    res.json({
      items,
      meeting: meeting
        ? { meetingDate: meeting.meetingDate, startsAt: meeting.startsAt, place: meeting.place }
        : defaultMeeting(),
      me: await councilName(req.council!.memberId),
      isEditor: await isCouncilEditor(req.council!),
    });
  } catch (err) {
    console.error('Council agenda error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/agenda — добавить пункт (2–500 символов). */
router.post('/agenda', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const title = String(req.body?.title ?? '').trim();
    if (title.length < 2 || title.length > 500) {
      return httpError(res, 400, 'Пункт повестки: 2–500 символов.');
    }
    const max = await db.councilAgendaItem.aggregate({ _max: { sort: true } });
    const sort = (max._max.sort ?? -1) + 1;

    const item = await db.councilAgendaItem.create({
      data: { title, author: await councilName(req.council!.memberId), sort },
    });
    res.status(201).json(item);
  } catch (err) {
    console.error('Council agenda add error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** DELETE /api/council/agenda/:id — удалить пункт (автор или дежурный/админ). */
router.delete('/agenda/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const item = await db.councilAgendaItem.findUnique({ where: { id } });
    if (!item) return httpError(res, 404, 'Пункт не найден');

    const editor = await isCouncilEditor(req.council!);
    const me = await councilName(req.council!.memberId);
    if (!editor && item.author !== me) {
      return httpError(res, 403, 'Удалить пункт может автор, дежурный председатель или админ.');
    }

    await db.councilAgendaItem.delete({ where: { id } });
    res.json({ ok: true });
  } catch (err) {
    console.error('Council agenda delete error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** POST /api/council/agenda/:id/toggle — отметить/снять «обсуждено» (дежурный/админ). */
router.post('/agenda/:id/toggle', requireCouncil, async (req: AuthRequest, res) => {
  try {
    if (!(await isCouncilEditor(req.council!))) {
      return httpError(res, 403, 'Отмечать «обсуждено» может дежурный или админ.');
    }
    const id = Number(req.params.id);
    const item = await db.councilAgendaItem.findUnique({ where: { id } });
    if (!item) return httpError(res, 404, 'Пункт не найден');

    const updated = await db.councilAgendaItem.update({
      where: { id },
      data: { discussed: !item.discussed },
    });
    res.json(updated);
  } catch (err) {
    console.error('Council agenda toggle error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/agenda/:id/move — поменять порядок с соседом (дежурный/админ).
 * dir: 'up' (вверх) или 'down' (вниз по умолчанию) — как в легаси.
 */
router.post('/agenda/:id/move', requireCouncil, async (req: AuthRequest, res) => {
  try {
    if (!(await isCouncilEditor(req.council!))) {
      return httpError(res, 403, 'Менять порядок может дежурный или админ.');
    }
    const id = Number(req.params.id);
    const dir = String(req.body?.dir ?? '') === 'up' ? -1 : 1;
    const cur = await db.councilAgendaItem.findUnique({ where: { id } });
    if (!cur) return httpError(res, 404, 'Пункт не найден');

    // Сосед: ближайший по (sort, id) в нужную сторону.
    const neighbors = await db.councilAgendaItem.findMany({
      where: {
        OR: [
          { sort: dir < 0 ? { lt: cur.sort } : { gt: cur.sort } },
          { sort: cur.sort, id: dir < 0 ? { lt: id } : { gt: id } },
        ],
      },
      orderBy: [{ sort: dir < 0 ? 'desc' : 'asc' }, { id: dir < 0 ? 'desc' : 'asc' }],
      take: 1,
    });
    const neighbor = neighbors[0];
    if (!neighbor) return res.json({ ok: true, moved: false }); // край списка

    // Меняем местами (при равных sort порядок добирает id — временный сдвиг).
    await db.$transaction([
      db.councilAgendaItem.update({ where: { id }, data: { sort: neighbor.sort } }),
      db.councilAgendaItem.update({ where: { id: neighbor.id }, data: { sort: cur.sort } }),
    ]);

    res.json({ ok: true, moved: true });
  } catch (err) {
    console.error('Council agenda move error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/agenda/rollover — перенос повестки на новую встречу
 * (порт CouncilAgendaRepository::rollOver): обсуждённое удаляется, остальное
 * помечается перенесённым. Дежурный/админ — это часть закрытия встречи.
 */
router.post('/agenda/rollover', requireCouncil, async (req: AuthRequest, res) => {
  try {
    if (!(await isCouncilEditor(req.council!))) {
      return httpError(res, 403, 'Перенести повестку может дежурный или админ.');
    }
    const [deleted] = await db.$transaction([
      db.councilAgendaItem.deleteMany({ where: { discussed: true } }),
      db.councilAgendaItem.updateMany({ data: { carriedOver: true } }),
    ]);
    res.json({ ok: true, deleted: deleted.count });
  } catch (err) {
    console.error('Council agenda rollover error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

export { router as councilMeetingRoutes };
