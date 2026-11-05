import { Router, Request, Response } from 'express';
import { db } from '../lib/db';
import { hashPassword, verifyPassword, randomToken } from '../lib/crypto';
import { signToken } from '../lib/auth';
import { requireAuth } from '../middleware/auth';

const router = Router();

// GET /poselenie/register
router.get('/register', (_req: Request, res: Response) => {
  res.json({ form: 'register' });
});

// POST /poselenie/register
router.post('/register', async (req: Request, res: Response) => {
  try {
    const { email, password, name } = req.body;
    if (!email || !password || !name) {
      return res.status(400).json({ error: 'Заполните все поля' });
    }

    const existing = await db.family.findUnique({ where: { email } });
    if (existing) {
      return res.status(409).json({ error: 'Email уже зарегистрирован' });
    }

    const family = await db.family.create({
      data: {
        email,
        passwordHash: hashPassword(password),
        name,
        status: 'pending',
      },
    });

    await db.loginAttempt.create({
      data: { email, ip: req.ip || 'unknown' },
    });

    res.json({ message: 'Заявка отправлена на модерацию' });
  } catch (err) {
    console.error('Register error:', err);
    res.status(500).json({ error: 'Ошибка регистрации' });
  }
});

// GET /poselenie/vhod
router.get('/login', (_req: Request, res: Response) => {
  res.json({ form: 'login' });
});

// POST /poselenie/login
router.post('/login', async (req: Request, res: Response) => {
  try {
    const { email, password } = req.body;
    if (!email || !password) {
      return res.status(400).json({ error: 'Введите email и пароль' });
    }

    const family = await db.family.findUnique({ where: { email } });
    if (!family || family.status === 'blocked') {
      return res.status(401).json({ error: 'Неверный email или пароль' });
    }

    if (!verifyPassword(password, family.passwordHash)) {
      return res.status(401).json({ error: 'Неверный email или пароль' });
    }

    if (family.status === 'pending') {
      return res.status(403).json({ error: 'Аккаунт ожидает модерации' });
    }

    const token = signToken({
      familyId: family.id,
      email: family.email,
      role: family.role,
    });

    res.cookie('token', token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      maxAge: 7 * 24 * 60 * 60 * 1000,
    });

    res.json({ token, redirect: '/poselenie/' });
  } catch (err) {
    console.error('Login error:', err);
    res.status(500).json({ error: 'Ошибка входа' });
  }
});

// GET /poselenie/vyhod
router.get('/logout', (_req: Request, res: Response) => {
  res.clearCookie('token');
  res.json({ redirect: '/poselenie/vhod' });
});

// POST /poselenie/vyhod
router.post('/logout', (_req: Request, res: Response) => {
  res.clearCookie('token');
  res.json({ redirect: '/poselenie/vhod' });
});

// GET /poselenie/vosstanovit
router.get('/forgot', (_req: Request, res: Response) => {
  res.json({ form: 'forgot' });
});

// POST /poselenie/vosstanovit
router.post('/forgot', async (req: Request, res: Response) => {
  try {
    const { email } = req.body;
    if (!email) return res.status(400).json({ error: 'Введите email' });

    const family = await db.family.findUnique({ where: { email } });
    if (!family) return res.json({ message: 'Если аккаунт существует, письмо отправлено' });

    const token = randomToken();
    await db.passwordReset.create({
      data: {
        token,
        familyId: family.id,
        expiresAt: new Date(Date.now() + 3600 * 1000), // 1 час
      },
    });

    // TODO: отправка письма со ссылкой /poselenie/sbros?token=...
    res.json({ message: 'Письмо отправлено' });
  } catch (err) {
    console.error('Forgot error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/sbros
router.get('/reset', (_req: Request, res: Response) => {
  res.json({ form: 'reset' });
});

// POST /poselenie/sbрос
router.post('/reset', async (req: Request, res: Response) => {
  try {
    const { token, password } = req.body;
    if (!token || !password) {
      return res.status(400).json({ error: 'Заполните все поля' });
    }

    const reset = await db.passwordReset.findUnique({
      where: { token },
      include: { family: true },
    });

    if (!reset || reset.expiresAt < new Date()) {
      return res.status(400).json({ error: 'Токен истёк' });
    }

    await db.family.update({
      where: { id: reset.familyId },
      data: { passwordHash: hashPassword(password) },
    });

    await db.passwordReset.delete({ where: { token } });
    res.json({ message: 'Пароль изменён' });
  } catch (err) {
    console.error('Reset error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as authRoutes };
