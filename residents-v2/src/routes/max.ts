import { Router, Request, Response } from 'express';
import { db } from '../lib/db';
import { signToken } from '../lib/auth';
import { verifyToken } from '../lib/auth';

const router = Router();
const MAX_BOT_TOKEN = process.env.SKAZKRAY_MAX_BOT_TOKEN!;
const MAX_GROUP_CHAT_ID = process.env.SKAZKRAY_MAX_GROUP_CHAT_ID!;

// GET /max (вход через MAX Mini App)
router.get('/', async (req: Request, res: Response) => {
  try {
    const { initData } = req.query;

    if (!initData) {
      return res.status(400).json({ error: 'Нет initData' });
    }

    // TODO: валидация подписи initData через MAX_BOT_TOKEN
    // const userData = MaxWebApp.verify(initData as string);

    res.json({ redirect: '/poselenie/' });
  } catch (err) {
    console.error('MAX entry error:', err);
    res.status(500).json({ error: 'Ошибка входа' });
  }
});

// POST /max/login (логика после валидации initData)
router.post('/login', async (req: Request, res: Response) => {
  try {
    const { initData } = req.body;
    // TODO: валидация initData

    const token = signToken({
      familyId: 0, // placeholder, real familyId from initData
      email: 'max@skazkray.local',
      role: 'resident',
    });

    res.json({ token, redirect: '/poselenie/' });
  } catch (err) {
    console.error('MAX login error:', err);
    res.status(500).json({ error: 'Ошибка входа' });
  }
});

// GET /max/gate (проверка членства в группе MAX)
router.get('/gate', async (req: Request, res: Response) => {
  try {
    // TODO: проверка членства в группе MAX
    res.json({ inGroup: true }); // placeholder
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as maxRoutes };
