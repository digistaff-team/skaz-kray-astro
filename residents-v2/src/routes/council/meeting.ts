import { Router } from 'express';
import { AuthRequest, requireCouncil } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError, isCouncilEditor, councilName } from '../../lib/councilHelpers';
import { applyDutyRotation, setDutyChair } from '../../lib/councilDuty';
import { defaultMeeting } from '../../lib/councilData';
import { ensureFixedAgenda, nextCustomAgendaSort, rollOverAgenda } from '../../services/agenda';

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
            startsAt: naiveIso(startsAt),
            endsAt: meeting.endsAt ? naiveIso(meeting.endsAt) : null,
            place: meeting.place,
            dutyChair: meeting.dutyChair,
            dutySecretary: meeting.dutySecretary,
            rotationIndex: meeting.rotationIndex,
          }
        : { ...defaultMeeting(), startsAt: naiveIso(startsAt), endsAt: null, rotationIndex: 0 },
      canEdit,
    });
  } catch (err) {
    console.error('Council meeting error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** Календарная дата по Москве как 'YYYY-MM-DD' (без зависящих от TZ toLocaleString). */
function mskDate(now = new Date()): string {
  return new Date(now.getTime() + 3 * 3600 * 1000).toISOString().slice(0, 10);
}

/** День встречи уже прошёл (по календарю Москвы)? naive-дата = московские стены. */
function isPastDay(d: Date): boolean {
  return d.toISOString().slice(0, 10) < mskDate();
}

/** Ближайший будущий понедельник — как upcomingMonday в легаси (стенное московское время). */
function upcomingMonday(time: string): Date {
  const now = new Date();
  // Стенные московские компоненты «сейчас».
  const wall = new Date(now.getTime() + 3 * 3600 * 1000);
  const dow = wall.getUTCDay(); // 0=Вс … 1=Пн
  const add = dow === 1 ? 0 : (8 - dow) % 7 || 7;
  const [h, m] = time.split(':').map(Number);
  const d = new Date(wall);
  if (add > 0) d.setUTCDate(d.getUTCDate() + add);
  d.setUTCHours(h, m, 0, 0);
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

    // datetime-local без смещения = московское стенное время (легаси parseLocal).
    const startsAt = parseLocalWall(startsRaw);
    const errors: Record<string, string> = {};
    if (!startsAt) errors.starts_at = 'Укажите дату и время.';
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

/**
 * «7 сентября 2026, 18:00–20:00» — подпись карточки (легаси meetingDate).
 *
 * starts_at в БД — naive datetime = МОСКОВСКОЕ стенное время (легаси-конвенция:
 * PHP-сервер жил в Europe/Moscow, strtotime/date не делали конверсий). Prisma
 * отдаёт такой столбец Date'ом, якоренным в UTC (18:00 → 18:00Z), поэтому
 * подпись собирается из getUTC*-компонентов: БЕЗ конверсии в московский
 * пояс, иначе к часам прибавлялось бы ещё +3 (тот самый баг «21:00»).
 */
export function formatMeetingDate(startsAt: Date): string {
  const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
    'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  const hh = String(startsAt.getUTCHours()).padStart(2, '0');
  const mm = String(startsAt.getUTCMinutes()).padStart(2, '0');
  return `${startsAt.getUTCDate()} ${months[startsAt.getUTCMonth()]} ${startsAt.getUTCFullYear()}, ${hh}:${mm}`;
}

/**
 * Наивный ISO для API: naive-дата не имеет смещения, суффикс Z убираем —
 * иначе браузер (МСК) в toLocalInput переведёт 18:00 в 21:00 при показе формы.
 */
function naiveIso(d: Date): string {
  return d.toISOString().replace(/\.\d{3}Z$/, '');
}

/**
 * Разбор datetime-local формы («2026-11-16T18:00», без смещения) как МОСКОВСКОГО
 * стенного времени: склеиваем Z, чтобы результат не зависел от TZ процесса
 * (легаси: strtotime на сервере в Europe/Moscow).
 */
function parseLocalWall(raw: string): Date | null {
  if (raw === '') return null;
  // Уже со смещением (на случай ручного вызова API) — парсим как есть.
  const withZone = /(?:Z|[+-]\d{2}:?\d{2})$/.test(raw) ? raw : raw + 'Z';
  const d = new Date(withZone);
  return Number.isNaN(d.getTime()) ? null : d;
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
 *
 * Постоянные пункты гарантируются через ensureFixedAgenda() — всегда первые,
 * их нельзя удалить/переместить, при переносе встречи они сбрасываются.
 */
router.get('/agenda', requireCouncil, async (req: AuthRequest, res) => {
  try {
    await ensureFixedAgenda();
    const [items, meeting] = await Promise.all([
      db.councilAgendaItem.findMany({ orderBy: [{ sort: 'asc' }, { id: 'asc' }] }),
      db.councilMeeting.findUnique({ where: { id: 1 } }),
    ]);
    res.json({
      items,
      meeting: meeting
        ? { meetingDate: meeting.meetingDate, startsAt: meeting.startsAt ? naiveIso(meeting.startsAt) : null, place: meeting.place }
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
    // Обычные пункты — sort >= 0, чтобы постоянные (sort < 0) оставались первыми.
    const item = await db.councilAgendaItem.create({
      data: { title, author: await councilName(req.council!.memberId), sort: await nextCustomAgendaSort() },
    });
    res.status(201).json(item);
  } catch (err) {
    console.error('Council agenda add error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** DELETE /api/council/agenda/:id — удалить пункт (автор или дежурный/админ). Постоянные удалить нельзя. */
router.delete('/agenda/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const item = await db.councilAgendaItem.findUnique({ where: { id } });
    if (!item) return httpError(res, 404, 'Пункт не найден');
    if (item.isFixed) return httpError(res, 403, 'Постоянный пункт повестки удалить нельзя.');

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
 * Постоянные пункты не двигаются; сосед ищется только среди обычных (is_fixed=false).
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
    if (cur.isFixed) return httpError(res, 403, 'Постоянный пункт повестки переместить нельзя.');

    // Сосед: ближайший по (sort, id) в нужную сторону среди обычных пунктов.
    const neighbors = await db.councilAgendaItem.findMany({
      where: {
        isFixed: false,
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
    const deleted = await rollOverAgenda();
    res.json({ ok: true, deleted });
  } catch (err) {
    console.error('Council agenda rollover error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

export { router as councilMeetingRoutes };
