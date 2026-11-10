import { Router, Response } from 'express';
import { AuthRequest, requireCouncil } from '../../middleware/auth';
import { db } from '../../lib/db';
import { hashPassword, verifyPassword, randomToken } from '../../lib/crypto';
import { signToken, CouncilPayload } from '../../lib/auth';
import { sendMail } from '../../lib/mailer';
import { httpError } from '../../lib/councilHelpers';

const router = Router();

const BASE_URL = process.env.BASE_URL || 'https://skaz-kray.ru';
/** Срок жизни ссылки сброса пароля, секунд (легаси reset_ttl). */
const RESET_TTL = 3600;

/**
 * Парольный вход члена Совета — порт residents/src/Controller/Council/AuthController.php.
 *
 * Раздел живёт отдельно от жителей: своя cookie council_token и свой payload
 * (kind:'council'), поэтому один и тот же человек может быть и семьёй, и членом
 * совета — входы не затирают друг друга (легаси: ключи сессии council_* vs family_*).
 */

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

/**
 * Сессия совета. path:'/' обязателен: без него cookie наследует путь запроса
 * (/api/council/login) и не уходит на остальные эндпоинты раздела.
 */
export function startCouncilSession(
  res: Response,
  member: { id: number; email: string; role: string },
): void {
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
    path: '/',
  });
}

// POST /api/council/login — вход члена совета (легаси POST /sovet/login).
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

    startCouncilSession(res, member);
    res.json({ ok: true, redirect: '/sovet/' });
  } catch (err) {
    console.error('Council login error:', err);
    httpError(res, 500, 'Ошибка входа');
  }
});

// POST /api/council/logout — гасит только cookie совета (легаси CouncilAuth::logout).
router.post('/logout', (_req: AuthRequest, res) => {
  res.clearCookie('council_token', { path: '/' });
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

/**
 * GET /api/council/reset?token= — проверка ссылки перед показом формы
 * (легаси showReset: findValid → valid/invalid). Ответ { valid: boolean }.
 * Токен в БД лежит как sha256 — здесь только сверка, смена пароля в POST.
 */
router.get('/reset', async (req: AuthRequest, res) => {
  try {
    const token = String(req.query?.token ?? '');
    const row = token
      ? await db.councilPasswordReset.findUnique({ where: { token } })
      : null;
    const valid = Boolean(row && row.expiresAt.getTime() > Date.now());
    res.json({ valid });
  } catch (err) {
    console.error('Council reset check error:', err);
    httpError(res, 500, 'Ошибка');
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

export { router as councilAuthRoutes };
