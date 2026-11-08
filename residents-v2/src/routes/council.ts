import { Router, Response } from 'express';
import { AuthRequest, requireCouncil, requireCouncilAdmin } from '../middleware/auth';
import { db } from '../lib/db';
import { hashPassword, verifyPassword, randomToken } from '../lib/crypto';
import { signToken, CouncilPayload } from '../lib/auth';
import { sendMail } from '../lib/mailer';
import {
  roleLabel, defaultMeeting, documents, protocols, directions, dutyNameForIndex, rosterMembers,
} from '../lib/councilData';
import { applyDutyRotation, setDutyChair } from '../lib/councilDuty';
import {
  syncExpense, requestExpenseIfNeeded,
} from '../lib/councilExpense';
import { buildLedgerReport } from '../services/ledgerReport';

const router = Router();

const BASE_URL = process.env.BASE_URL || 'https://skaz-kray.ru';
/** Срок жизни ссылки сброса пароля, секунд (легаси reset_ttl). */
const RESET_TTL = 3600;

// ═══════════════════════ Хелперы ═══════════════════════

/**
 * Ограничение неудачных попыток (порт LoginThrottle под login_attempts):
 * по ключу «scope:идентификатор» и по IP (лимит шире — за одним NAT полдеревни).
 */
async function throttleExceeded(key: string, max: number, windowSec: number): Promise<boolean> {
  const cutoff = new Date(Date.now() - windowSec * 1000);
  const recent = await db.loginAttempt.count({
    where: { email: key, attemptedAt: { gte: cutoff } },
  });
  return recent >= max;
}

async function loginBlocked(scope: string, email: string, ip: string): Promise<boolean> {
  return (await throttleExceeded(scope + email, 5, 900))
    || (await throttleExceeded(scope + 'ip:' + ip, 20, 900));
}

async function recordLoginFailure(scope: string, email: string, ip: string): Promise<void> {
  await db.loginAttempt.createMany({
    data: [
      { email: scope + email, ip },
      { email: scope + 'ip:' + ip, ip },
    ],
  });
}

/** Имя члена совета по токену (автор операций — из сессии, не из формы). */
async function councilName(memberId: number): Promise<string> {
  const m = await db.councilMember.findUnique({
    where: { id: memberId },
    select: { name: true },
  });
  return m?.name ?? '';
}

/**
 * Редактор встречи/повестки: админ ИЛИ текущий Дежурный председатель
 * (MeetingController::requireEditor / AgendaController::isEditor в легаси).
 */
async function isEditor(council: CouncilPayload): Promise<boolean> {
  if (council.role === 'admin') return true;
  const m = await db.councilMember.findUnique({
    where: { id: council.memberId },
    select: { isDutyChair: true },
  });
  return m?.isDutyChair ?? false;
}

function httpError(res: Response, status: number, error: string): void {
  res.status(status).json({ error });
}

// ═══════════════════════ Авторизация ═══════════════════════

// POST /api/council/login — вход члена совета (легаси POST /sovet/login).
// Своя cookie council_token: жительский токен не затирается (легаси —
// раздельные ключи сессии council_* и family_*).
router.post('/login', async (req: AuthRequest, res) => {
  try {
    const email = String(req.body?.email ?? '').trim();
    const password = String(req.body?.password ?? '');
    const ip = req.ip || '0.0.0.0';

    if (!email || !password) {
      return httpError(res, 400, 'Введите email и пароль');
    }
    if (await loginBlocked('council:', email, ip)) {
      return httpError(res, 429, 'Слишком много попыток. Попробуйте позже.');
    }

    const member = await db.councilMember.findUnique({ where: { email } });
    const ok = member && verifyPassword(password, member.passwordHash);
    if (!ok || !member) {
      await recordLoginFailure('council:', email, ip);
      return httpError(res, 401, 'Неверный email или пароль.');
    }
    if (member.status !== 'active') {
      return httpError(res, 403, 'Доступ заблокирован. Обратитесь к администратору совета.');
    }

    const token: CouncilPayload = {
      kind: 'council',
      memberId: member.id,
      email: member.email,
      role: member.role,
    };
    res.cookie('council_token', signToken(token), {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      maxAge: 7 * 24 * 60 * 60 * 1000,
    });
    res.json({ ok: true, redirect: '/sovet/' });
  } catch (err) {
    console.error('Council login error:', err);
    httpError(res, 500, 'Ошибка входа');
  }
});

// POST /api/council/logout — гасит только cookie совета (легаси CouncilAuth::logout).
router.post('/logout', (_req: AuthRequest, res) => {
  res.clearCookie('council_token');
  res.json({ ok: true, redirect: '/sovet/vhod/' });
});

// POST /api/council/forgot — ссылка сброса пароля (легаси forgot).
// Всегда отвечает ok — не раскрываем, есть ли email среди членов совета.
router.post('/forgot', async (req: AuthRequest, res) => {
  try {
    const email = String(req.body?.email ?? '').trim();
    const ip = req.ip || '0.0.0.0';
    if (await throttleExceeded('cforgot:' + ip, 10, 900)) {
      return res.json({ ok: true });
    }
    await db.loginAttempt.create({ data: { email: 'cforgot:' + ip, ip } });

    const member = email
      ? await db.councilMember.findUnique({ where: { email } })
      : null;
    if (member && member.status === 'active') {
      const token = randomToken(64);
      await db.councilPasswordReset.create({
        data: {
          token,
          memberId: member.id,
          expiresAt: new Date(Date.now() + RESET_TTL * 1000),
        },
      });
      const link = `${BASE_URL}/sovet/sbros/?token=${token}`;
      // После ответа: по задержке SMTP было бы видно, что такой адрес есть.
      await sendMail(
        email,
        'Восстановление пароля — Попечительский совет',
        `<p>Здравствуйте!</p><p>Чтобы задать новый пароль для входа в раздел Попечительского совета, перейдите по <a href="${link}">ссылке</a> (действует час).</p><p>Если вы не запрашивали сброс — просто игнорируйте письмо.</p>`,
      ).catch((e) => console.error('Council forgot mail error:', e));
    }
    res.json({ ok: true });
  } catch (err) {
    console.error('Council forgot error:', err);
    res.json({ ok: true }); // даже при сбое не раскрываем наличие email
  }
});

// POST /api/council/reset — новый пароль по токену (легаси reset).
router.post('/reset', async (req: AuthRequest, res) => {
  try {
    const token = String(req.body?.token ?? '');
    const password = String(req.body?.password ?? '');
    const row = token
      ? await db.councilPasswordReset.findUnique({ where: { token } })
      : null;
    if (!row || row.expiresAt.getTime() < Date.now()) {
      return httpError(res, 400, 'Ссылка недействительна или устарела. Запросите новую.');
    }
    if (password.length < 8) {
      return httpError(res, 400, 'Пароль не короче 8 символов.');
    }
    await db.councilMember.update({
      where: { id: row.memberId },
      data: { passwordHash: hashPassword(password) },
    });
    await db.councilPasswordReset.delete({ where: { token } }).catch(() => undefined);
    res.json({ ok: true, redirect: '/sovet/vhod/' });
  } catch (err) {
    console.error('Council reset error:', err);
    httpError(res, 500, 'Ошибка смены пароля');
  }
});

// POST /api/council/password — смена пароля залогиненным членом (легаси changePassword).
router.post('/password', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const current = String(req.body?.current ?? '');
    const next = String(req.body?.password ?? '');
    const member = await db.councilMember.findUnique({
      where: { id: req.council!.memberId },
    });
    if (!member || !verifyPassword(current, member.passwordHash)) {
      return httpError(res, 400, 'Текущий пароль указан неверно.');
    }
    if (next.length < 8) {
      return httpError(res, 400, 'Новый пароль не короче 8 символов.');
    }
    await db.councilMember.update({
      where: { id: member.id },
      data: { passwordHash: hashPassword(next) },
    });
    res.json({ ok: true, redirect: '/sovet/' });
  } catch (err) {
    console.error('Council password error:', err);
    httpError(res, 500, 'Ошибка смены пароля');
  }
});

// ═══════════════════════ Главная и направления ═══════════════════════

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
router.get('/directions', requireCouncil, (_req: AuthRequest, res) => {
  res.json(directions());
});

// === Задачи совета ===

// GET /api/council/tasks — задачи совета (все члены совета).
router.get('/tasks', requireCouncil, async (_req: AuthRequest, res) => {
  try {
    const tasks = await db.councilTask.findMany({
      orderBy: { createdAt: 'desc' },
    });
    res.json(tasks);
  } catch (err) {
    console.error('Council tasks error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/council/tasks — создать задачу совета (все члены совета).
router.post('/tasks', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const { title, description, assignee, priority, dueDate } = req.body;
    const task = await db.councilTask.create({
      data: {
        title,
        description: description || null,
        assignee: assignee || '',
        priority: priority || 'средняя',
        dueDate: dueDate ? new Date(dueDate) : null,
      },
    });
    res.json({ id: task.id, message: 'Задача создана' });
  } catch (err) {
    console.error('Council task create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/council/tasks/:id/status — смена статуса задачи.
router.post('/tasks/status/:id', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const { status, progress } = req.body;
    const task = await db.councilTask.update({
      where: { id: Number(req.params.id) },
      data: {
        status,
        progress: Number(progress) || undefined,
        completedAt: status === 'выполнена' ? new Date() : null,
      },
    });
    res.json(task);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === Страницы совета ===

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
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === Бюджет ===

// GET /api/council/budget?mesyac=YYYY-MM — книга расходов совета.
// Тот же LedgerReport, что у страницы жителей /poselenie/byudzhet — цифры совпадают.
router.get('/budget', requireCouncil, async (req: AuthRequest, res) => {
  try {
    const ym = typeof req.query.mesyac === 'string' ? req.query.mesyac : null;
    res.json(await buildLedgerReport(ym));
  } catch (err) {
    console.error('Council budget error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === Уведомления ===

// POST /api/council/notify — только админ совета.
router.post('/notify', requireCouncilAdmin, async (req: AuthRequest, res) => {
  try {
    // TODO: отправка уведомления через Telegram Bot API
    res.json({ message: 'Уведомление отправлено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as councilRoutes };
