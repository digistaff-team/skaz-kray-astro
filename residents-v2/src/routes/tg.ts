import { Router, Request, Response } from 'express';
import { db } from '../lib/db';
import { signToken } from '../lib/auth';
import { requireAuth } from '../middleware/auth';

const router = Router();
const BOT_TOKEN = process.env.SKAZKRAY_BOT_TOKEN!;
const GROUP_CHAT_ID = process.env.SKAZKRAY_GROUP_CHAT_ID!;
const WEBHOOK_SECRET = process.env.SKAZKRAY_WEBHOOK_SECRET!;

// GET /poselenie/tg (авто-логин через Telegram Mini App)
router.get('/login', async (req: Request, res: Response) => {
  try {
    const { auth_date, query_id, user } = req.body || req.query;

    if (!query_id) {
      return res.status(400).json({ error: 'Нет query_id' });
    }

    // TODO: валидация initData через crypto
    // for now, assume query_id is valid (production needs proper init data validation)

    const telegramId = Number(user?.id);
    if (!telegramId) {
      return res.status(400).json({ error: 'Нет user.id' });
    }

    // Проверяем членство в группе
    // TODO: вызов Telegram Bot API getChatMember для GROUP_CHAT_ID

    let family = await db.family.findUnique({ where: { telegramId } });

    if (!family) {
      // Создаём временный аккаунт
      family = await db.family.create({
        data: {
          telegramId,
          email: `tg_${telegramId}@skazkray.local`,
          passwordHash: '$2a$10$placeholder', // placeholder
          name: user?.first_name || 'Новый житель',
          status: 'PENDING',
        },
      });
    }

    if (family.status === 'BLOCKED') {
      return res.status(403).json({ error: 'Аккаунт заблокирован' });
    }

    const token = signToken({
      familyId: family.id,
      email: family.email,
      role: family.role,
    });

    res.json({ token, redirect: '/poselenie/' });
  } catch (err) {
    console.error('TG login error:', err);
    res.status(500).json({ error: 'Ошибка входа' });
  }
});

// POST /poselenie/tg/webhook (обработка кнопок из уведомлений)
router.post('/webhook', async (req: Request, res: Response) => {
  try {
    const secret = req.headers['x-telegram-bot-api-secret-token'] as string;
    if (secret !== WEBHOOK_SECRET) {
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
        text: data,
      },
    });

    res.status(200).json({ ok: true });
  } catch (err) {
    console.error('TG webhook error:', err);
    res.status(500).json({ error: 'Ошибка webhook' });
  }
});

// POST /poselenie/tg/gate (проверка членства в группе)
router.post('/gate', async (req: Request, res: Response) => {
  try {
    const { telegramId } = req.body;
    if (!telegramId) return res.status(400).json({ error: 'Нет telegramId' });

    // TODO: вызов Telegram Bot API getChatMember
    // const inGroup = await checkTelegramMembership(telegramId);

    res.json({ inGroup: true }); // placeholder
  } catch (err) {
    res.status(500).json({ error: 'Ошибка проверки' });
  }
});

export { router as tgRoutes };
