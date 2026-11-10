import { Router, Response } from 'express';
import { AuthRequest, requireCouncil, requireCouncilAdmin } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError } from '../../lib/councilHelpers';
import {
  roleLabel, defaultMeeting, documents, protocols, directions, dutyNameForIndex, rosterMembers,
} from '../../lib/councilData';
import { buildLedgerReport } from '../../services/ledgerReport';

import { councilAuthRoutes } from './auth';
import { councilTaskRoutes } from './tasks';
import { councilMeetingRoutes } from './meeting';
import { councilLedgerRoutes } from './ledger';
import { councilAdminRoutes } from './admin';
import { councilTgRoutes } from './tg';

const router = Router();

/**
 * Раздел Попечительского совета — объединитель портированных контроллеров.
 * Порядок монтирования важен: /admin/members и /ledger/categories объявлены
 * внутри своих модулей раньше параметрических /:id, а между модулями конфликтов
 * нет (у каждого свой префикс).
 */
router.use(councilAuthRoutes); // /login, /logout, /forgot, /reset, /password
router.use(councilTgRoutes); // /tg/login, /tg/claim, /tg/me
router.use(councilAdminRoutes); // /admin/…
router.use(councilMeetingRoutes); // /meeting, /agenda, /duty/handoff
router.use(councilTaskRoutes); // /tasks, /subtasks
router.use(councilLedgerRoutes); // /ledger, /ledger/categories

/**
 * GET /api/council/me — сессия для лэйаута статических страниц /sovet/*:
 * кто вошёл и видна ли кнопка «Участники» (только админ). Лёгкий ответ
 * без запросов к встрече/повестке — лэйаут грузится на каждой странице.
 * 401 без cookie council_token — как CouncilAuth::requireLogin().
 */
router.get('/me', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const me = await db.councilMember.findUnique({
      where: { id: req.council!.memberId },
      select: { id: true, name: true, role: true, status: true, isDutyChair: true },
    });
    if (!me || me.status !== 'active') return httpError(res, 401, 'Не авторизован');
    res.json({ me, roleLabel: roleLabel(me) });
  } catch (err) {
    console.error('Council me error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * GET /api/council/home — данные главной /sovet/ (легаси PagesController::home):
 * карточка собрания, повестка, счётчики, ротация дежурных, статика CouncilData.
 */
router.get('/home', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const council = req.council!;
    const [me, meeting, agendaItems, activeCount] = await Promise.all([
      db.councilMember.findUnique({ where: { id: council.memberId } }),
      db.councilMeeting.findUnique({ where: { id: 1 } }),
      db.councilAgendaItem.findMany({ orderBy: [{ sort: 'asc' }, { id: 'asc' }] }),
      db.councilTask.count({ where: { status: { not: 'выполнена' } } }),
    ]);
    if (!me) return httpError(res, 401, 'Не авторизован');

    const meet = meeting
      ? {
          date: meeting.meetingDate,
          place: meeting.place,
          dutyChair: meeting.dutyChair,
          dutySecretary: meeting.dutySecretary,
          dutyAckAt: meeting.dutyAckAt ? meeting.dutyAckAt.toISOString() : null,
        }
      : defaultMeeting();

    const canEditMeeting = council.role === 'admin' || me.isDutyChair;
    let dutyCandidates: Array<{ id: number; name: string }> = [];
    if (canEditMeeting) {
      const all = await db.councilMember.findMany({ orderBy: { createdAt: 'asc' } });
      dutyCandidates = all
        .filter((m) => m.status === 'active' && m.role !== 'admin' && !m.isDutyChair)
        .map((m) => ({ id: m.id, name: m.name }));
    }

    res.json({
      me: { id: me.id, name: me.name, role: me.role, isDutyChair: me.isDutyChair },
      roleLabel: roleLabel(me),
      meeting: meet,
      agendaItems,
      activeCount,
      directionsCount: directions().length,
      canEditMeeting,
      dutyCandidates,
      documents: documents(),
      protocols: protocols(),
      roster: rosterMembers(),
      rotationName: dutyNameForIndex(meeting?.rotationIndex ?? 0),
    });
  } catch (err) {
    console.error('Council home error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// GET /api/council/directions — статические направления работы (легаси directions()).
router.get('/directions', requireCouncil, (_req: AuthRequest, res: Response) => {
  res.json(directions());
});

// GET /api/council — сводка активных задач (корневой экран).
router.get('/', requireCouncil, async (_req: AuthRequest, res) => {
  try {
    const tasks = await db.councilTask.findMany({
      where: { status: { not: 'выполнена' } },
      orderBy: { createdAt: 'desc' },
      take: 20,
    });
    res.json({ tasks });
  } catch (err) {
    console.error('Council root error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// GET /api/council/budget?mesyac=YYYY-MM — книга расходов совета.
// Тот же LedgerReport, что у страницы жителей /poselenie/byudzhet — цифры совпадают.
router.get('/budget', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const ym = typeof req.query.mesyac === 'string' ? req.query.mesyac : null;
    res.json(await buildLedgerReport(ym));
  } catch (err) {
    console.error('Council budget error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

// POST /api/council/notify — только админ совета.
router.post('/notify', requireCouncilAdmin, async (_req: AuthRequest, res: Response) => {
  res.json({ message: 'Уведомление отправлено' });
});

export { router as councilRoutes };
