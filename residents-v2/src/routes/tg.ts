import { Router, Request, Response } from 'express';
import { db } from '../lib/db';
import { signToken } from '../lib/auth';
import { hashPassword, randomToken } from '../lib/crypto';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { verifyInitData, chatMemberStatus, decodeStartParam } from '../lib/telegram';

const router = Router();

// Все process.env читаются в момент вызова, а не на уровне модуля:
// на Vercel module-level чтение даёт undefined (урок JWT_SECRET).

// POST /api/tg/login — авто-логин через Telegram Mini App (порт
// TgAuthController::login). Тело — JSON или form-urlencoded (оба парсера
// смонтированы в server.ts). Контракт легаси: 401 только для неверной
// подписи initData; остальные причины (гейт/blocked) — HTTP 200.
router.post('/login', async (req: Request, res: Response) => {
  try {
    const botToken = process.env.SKAZKRAY_BOT_TOKEN || '';
    const groupChatId = process.env.SKAZKRAY_GROUP_CHAT_ID || '';
    const body = (req.body || {}) as { initData?: unknown; startapp?: unknown };
    const initData = typeof body.initData === 'string' ? body.initData : '';
    const startapp = typeof body.startapp === 'string' ? body.startapp : '';

    const user = verifyInitData(initData, botToken, { allowSignatureVariant: true });
    if (!user) {
      return res.status(401).json({ ok: false, reason: 'invalid' });
    }

    const redirect = decodeStartParam(startapp);

    // Гейт подписки на группу жителей (fail-closed: 'error' не пускает внутрь).
    const status = await chatMemberStatus(user.id, botToken, groupChatId);
    if (status !== 'subscribed') {
      return res.json({ ok: false, reason: status }); // not_subscribed | error
    }

    const telegramId = BigInt(user.id);
    const username = user.username;
    let family = await db.family.findUnique({ where: { telegramId } });
    if (!family) {
      // Порт FamilyRepository::createTelegramFamily: сразу active/resident —
      // членство в группе уже подтверждено гейтом, модерация не нужна.
      let name = [user.firstName, user.lastName].filter(Boolean).join(' ');
      if (!name) name = username ? '@' + username : 'Житель';
      if (name.length > 160) name = Array.from(name).slice(0, 160).join(''); // VarChar(160), как mb_substr
      try {
        family = await db.family.create({
          data: {
            telegramId,
            email: `tg${user.id}@telegram.local`,
            telegramUsername: username,
            passwordHash: hashPassword(randomToken()), // пароль не используется: вход только через TG
            name,
            status: 'active',
            role: 'resident',
            approvedAt: new Date(),
          },
        });
      } catch (e) {
        // Гонка P2002: параллельный первый вход успел создать аккаунт — берём его.
        if ((e as { code?: string }).code !== 'P2002') throw e;
        family = await db.family.findUnique({ where: { telegramId } });
        if (!family) throw e;
      }
    } else if ((family.telegramUsername ?? null) !== username) {
      // @username мог измениться/появиться — держим актуальным для ссылки «Tg».
      await db.family.update({ where: { id: family.id }, data: { telegramUsername: username } });
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
      path: '/', // обязателен: иначе cookie не уйдёт за пределы /api/tg
    });
    return res.json({ ok: true, token, redirect });
  } catch (err) {
    console.error('TG login error:', err);
    return res.status(500).json({ ok: false, reason: 'error' });
  }
});

// GET /api/tg/session — поддержка legacy-шортката «уже вошёл»: 200 = сессия жива.
router.get('/session', requireAuth, (_req: AuthRequest, res: Response) => {
  res.json({ ok: true });
});

// GET /api/tg/gate — членство в группе для текущего аккаунта; 'error' ⇒ inGroup:false.
router.get('/gate', requireAuth, async (req: AuthRequest, res: Response) => {
  try {
    if (!req.user) return res.status(401).json({ error: 'Не авторизован' });
    const family = await db.family.findUnique({ where: { id: req.user.familyId } });
    if (!family || family.telegramId === null) {
      return res.json({ inGroup: false });
    }
    const status = await chatMemberStatus(
      family.telegramId.toString(),
      process.env.SKAZKRAY_BOT_TOKEN || '',
      process.env.SKAZKRAY_GROUP_CHAT_ID || '',
    );
    return res.json({ inGroup: status === 'subscribed' });
  } catch (err) {
    console.error('TG gate error:', err);
    return res.status(500).json({ error: 'Ошибка проверки' });
  }
});

// POST /api/tg/webhook — обработка кнопок из уведомлений (логика легаси
// без изменений; WEBHOOK_SECRET читается в момент вызова).
router.post('/webhook', async (req: Request, res: Response) => {
  try {
    const webhookSecret = process.env.SKAZKRAY_WEBHOOK_SECRET || '';
    const secret = req.headers['x-telegram-bot-api-secret-token'] as string;
    if (!webhookSecret || secret !== webhookSecret) {
      return res.status(403).json({ error: 'Неверный секрет' });
    }

    const { callback_query } = req.body;
    if (!callback_query) return res.status(200).json({ ok: true });

    const { data, message } = callback_query;
    const userId = message?.from?.id;

    if (!data || !userId) {
      return res.status(200).json({ ok: true });
    }

    // Разбираем данные кнопки (формат: action:id)
    const [action, id] = data.split(':');
    if (!action || !id) {
      return res.status(200).json({ ok: true });
    }

    // TODO: обработка действий (approve, reject, confirm и т.д.)

    await db.telegramMessage.create({
      data: {
        messageId: message.message_id,
        chatId: message.chat?.id || 0,
        context: 'webhook',
        text: data,
      },
    });

    res.status(200).json({ ok: true });
  } catch (err) {
    console.error('TG webhook error:', err);
    res.status(500).json({ error: 'Ошибка webhook' });
  }
});

export { router as tgRoutes };
