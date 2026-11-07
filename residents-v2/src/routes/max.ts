import { Router, Request, Response } from 'express';
import { db } from '../lib/db';
import { signToken } from '../lib/auth';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { hashPassword, randomToken } from '../lib/crypto';
import { decodeStartParam } from '../lib/telegram';
import { verifyMaxInitData, maxChatMemberStatus } from '../lib/max';

const router = Router();

// Все process.env читаются в момент вызова, а не на уровне модуля (см. tg.ts).

// POST /api/max/login — зеркало POST /api/tg/login для MAX (порт
// MaxAuthController::login). Раньше здесь был баг: JWT familyId:0 выдавался
// ВСЕМ без проверок. Контракт легаси: 401 только для неверной подписи.
router.post('/login', async (req: Request, res: Response) => {
  try {
    const botToken = process.env.SKAZKRAY_MAX_BOT_TOKEN || '';
    const groupChatId = process.env.SKAZKRAY_MAX_GROUP_CHAT_ID || '';
    const body = (req.body || {}) as { initData?: unknown; startapp?: unknown };
    const initData = typeof body.initData === 'string' ? body.initData : '';
    const startapp = typeof body.startapp === 'string' ? body.startapp : '';

    const user = verifyMaxInitData(initData, botToken);
    if (!user) {
      return res.status(401).json({ ok: false, reason: 'invalid' });
    }

    const redirect = decodeStartParam(startapp);

    // Гейт членства в группе жителей MAX (fail-closed: 'error' не пускает внутрь).
    const status = await maxChatMemberStatus(user.id, botToken, groupChatId);
    if (status !== 'subscribed') {
      return res.json({ ok: false, reason: status }); // not_subscribed | error
    }

    const maxUserId = BigInt(user.id);
    let family = await db.family.findUnique({ where: { maxUserId } });
    if (!family) {
      // Порт FamilyRepository::createMaxFamily: сразу active/resident;
      // username у MAX в справочнике не хранится (аналог createTelegramFamily).
      let name = [user.firstName, user.lastName].filter(Boolean).join(' ');
      if (!name) name = user.username ? '@' + user.username : 'Житель';
      if (name.length > 160) name = Array.from(name).slice(0, 160).join('');
      try {
        family = await db.family.create({
          data: {
            maxUserId,
            email: `max${user.id}@max.local`,
            passwordHash: hashPassword(randomToken()), // пароль не используется: вход только через MAX
            name,
            status: 'active',
            role: 'resident',
            approvedAt: new Date(),
          },
        });
      } catch (e) {
        // Гонка P2002: параллельный первый вход успел создать аккаунт — берём его.
        if ((e as { code?: string }).code !== 'P2002') throw e;
        family = await db.family.findUnique({ where: { maxUserId } });
        if (!family) throw e;
      }
    }

    if (family.status === 'blocked') {
      return res.json({ ok: false, reason: 'blocked' });
    }

    const token = signToken({ familyId: family.id, email: family.email, role: family.role });
    res.cookie('token', token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      maxAge: 7 * 24 * 60 * 60 * 1000,
      path: '/',
    });
    return res.json({ ok: true, token, redirect });
  } catch (err) {
    console.error('MAX login error:', err);
    return res.status(500).json({ ok: false, reason: 'error' });
  }
});

// GET /api/max/gate — членство в группе MAX для текущего аккаунта (error ⇒ false).
router.get('/gate', requireAuth, async (req: AuthRequest, res: Response) => {
  try {
    if (!req.user) return res.status(401).json({ error: 'Не авторизован' });
    const family = await db.family.findUnique({ where: { id: req.user.familyId } });
    if (!family || family.maxUserId === null) {
      return res.json({ inGroup: false });
    }
    const status = await maxChatMemberStatus(
      family.maxUserId.toString(),
      process.env.SKAZKRAY_MAX_BOT_TOKEN || '',
      process.env.SKAZKRAY_MAX_GROUP_CHAT_ID || '',
    );
    return res.json({ inGroup: status === 'subscribed' });
  } catch (err) {
    console.error('MAX gate error:', err);
    return res.status(500).json({ error: 'Ошибка проверки' });
  }
});

export { router as maxRoutes };
