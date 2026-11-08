import { Router } from 'express';
import crypto from 'crypto';
import { AuthRequest } from '../../middleware/auth';
import { db } from '../../lib/db';
import { httpError } from '../../lib/councilHelpers';
import { verifyInitData, InitDataUser } from '../../lib/telegram';
import { startCouncilSession } from './auth';

const router = Router();

/**
 * Вход члена Совета через Telegram Mini App — порт
 * residents/src/Controller/Council/TgAuthController.php.
 *
 * Поток:
 *  1. Mini App отдаёт initData на POST /api/council/tg/login. Подпись проверяет
 *     verifyInitData (общий с входом жителей).
 *  2. Telegram уже привязан к записи ростера → вход, cookie council_token.
 *  3. Не привязан → reason: 'need_surname'; клиент показывает форму фамилии и
 *     одноразового кода, выданного администратором совета. Одной фамилии мало:
 *     состав совета не секрет.
 *
 * Гейта по членству в группе (в отличие от входа жителей) здесь НЕТ — доступ
 * ограничен самим списком совета.
 */

const CLAIM_MAX_ATTEMPTS = 5;
const CLAIM_WINDOW_SEC = 900;

/** base64url(путь) → безопасный путь внутри /sovet, иначе '/sovet'. */
function decodeCouncilStart(startParam: unknown): string {
  const fallback = '/sovet';
  if (typeof startParam !== 'string' || startParam === '') return fallback;
  if (!/^[A-Za-z0-9\-_]+={0,2}$/.test(startParam)) return fallback;
  try {
    const b64 = startParam.replace(/-/g, '+').replace(/_/g, '/');
    const pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4));
    const decoded = Buffer.from(b64 + pad, 'base64').toString('utf8');
    if (!decoded.startsWith('/sovet') || decoded.includes('\uFFFD')) return fallback;
    return decoded;
  } catch {
    return fallback;
  }
}

/** Подпись initData — токен бота читается в момент вызова (урок Vercel). */
function verify(req: AuthRequest): InitDataUser | null {
  const botToken = process.env.SKAZKRAY_BOT_TOKEN || '';
  const initData = typeof req.body?.initData === 'string' ? req.body.initData : '';
  return verifyInitData(initData, botToken, { allowSignatureVariant: true });
}

/** Сессия совета — общая с парольным входом (см. ./auth), cookie council_token. */

/** Код в каноническом виде: регистр и разделители не важны. */
function normalizeClaimCode(code: string): string {
  return code.toUpperCase().replace(/[^A-Z0-9]/g, '');
}

function hashClaimCode(code: string): string {
  return crypto.createHash('sha256').update(code).digest('hex');
}

/** Лимит неудачных попыток привязки по Telegram-аккаунту (его id подписан). */
async function claimThrottled(telegramId: string): Promise<boolean> {
  const cutoff = new Date(Date.now() - CLAIM_WINDOW_SEC * 1000);
  const recent = await db.loginAttempt.count({
    where: { email: 'council-claim:tg:' + telegramId, attemptedAt: { gte: cutoff } },
  });
  return recent >= CLAIM_MAX_ATTEMPTS;
}

/**
 * POST /api/council/tg/login — вход по initData.
 * 401 только при неверной подписи (контракт легаси); остальные причины — 200.
 */
router.post('/tg/login', async (req: AuthRequest, res) => {
  try {
    const user = verify(req);
    if (!user) return res.status(401).json({ ok: false, reason: 'invalid' });

    const member = await db.councilMember.findUnique({
      where: { telegramId: BigInt(user.id) },
    });
    if (!member) return res.json({ ok: false, reason: 'need_surname' });
    if (member.status !== 'active') return res.json({ ok: false, reason: 'blocked' });

    startCouncilSession(res, member);
    res.json({ ok: true, redirect: decodeCouncilStart(req.body?.startapp) });
  } catch (err) {
    console.error('Council TG login error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/**
 * POST /api/council/tg/claim — привязка Telegram по фамилии и коду админа.
 * Запись должна быть активной, ещё не привязанной, код — непросроченным;
 * после успеха код гаснет (одноразовый).
 */
router.post('/tg/claim', async (req: AuthRequest, res) => {
  try {
    const user = verify(req);
    if (!user) return httpError(res, 401, 'Не удалось подтвердить Telegram. Откройте раздел через бота @SkazKray_bot.');

    const telegramId = BigInt(user.id);

    // Уже привязан (повторная отправка формы) — просто входим.
    const existing = await db.councilMember.findUnique({ where: { telegramId } });
    if (existing) {
      if (existing.status !== 'active') {
        return httpError(res, 403, 'Ваш доступ заблокирован. Обратитесь к администратору совета.');
      }
      startCouncilSession(res, existing);
      return res.json({ ok: true, redirect: '/sovet' });
    }

    const surname = String(req.body?.surname ?? '').trim();
    const code = normalizeClaimCode(String(req.body?.code ?? ''));
    if (surname === '' || code === '') {
      return httpError(res, 400, 'Введите фамилию и код привязки.');
    }
    if (await claimThrottled(user.id)) {
      return httpError(res, 429, 'Слишком много попыток. Попробуйте позже.');
    }

    const now = new Date();
    const candidates = await db.councilMember.findMany({
      where: { claimCodeHash: hashClaimCode(code), telegramId: null, status: 'active' },
    });
    // Фамилию сравниваем в нижнем регистре: в БД она может быть записана иначе.
    const target = candidates.find(
      (m) => m.surname.trim().toLowerCase() === surname.toLowerCase(),
    );
    const valid = target
      && target.claimCodeExpires !== null
      && target.claimCodeExpires.getTime() >= now.getTime();

    if (!valid) {
      await db.loginAttempt.create({
        data: { email: 'council-claim:tg:' + user.id, ip: req.ip || '0.0.0.0' },
      });
      return httpError(
        res, 400,
        'Фамилия или код не подошли. Код действует 7 дней и только один раз — если он истёк, попросите новый у администратора совета.',
      );
    }

    // Условие telegramId: null в updateMany закрывает гонку двух параллельных привязок.
    const bound = await db.councilMember.updateMany({
      where: { id: target!.id, telegramId: null },
      data: { telegramId, claimCodeHash: null, claimCodeExpires: null },
    });
    if (bound.count !== 1) {
      return httpError(res, 409, 'Эту запись уже привязали. Обратитесь к администратору совета.');
    }

    const fresh = await db.councilMember.findUnique({ where: { id: target!.id } });
    if (!fresh) return httpError(res, 500, 'Ошибка');
    startCouncilSession(res, fresh);
    res.json({ ok: true, redirect: '/sovet' });
  } catch (err) {
    console.error('Council TG claim error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

/** GET /api/council/tg/me — кто пришёл по initData (для экрана привязки). */
router.get('/tg/me', async (req: AuthRequest, res) => {
  try {
    const user = verify(req);
    if (!user) return res.status(401).json({ ok: false, reason: 'invalid' });
    const member = await db.councilMember.findUnique({ where: { telegramId: BigInt(user.id) } });
    res.json({
      telegramId: user.id,
      username: user.username,
      linked: Boolean(member),
      name: member ? member.name : null,
    });
  } catch (err) {
    console.error('Council TG me error:', err);
    httpError(res, 500, 'Ошибка');
  }
});

export { router as councilTgRoutes };
