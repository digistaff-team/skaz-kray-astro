import { Router } from 'express';
import { requireAuth, AuthRequest, requireAdmin } from '../middleware/auth';
import { db } from '../lib/db';
import { hashPassword, verifyPassword, randomToken } from '../lib/crypto';
import { signToken } from '../lib/auth';

const router = Router();

// === Авторизация совета ===

// GET /sovet/vhod
router.get('/login', (_req: AuthRequest, res) => {
  res.json({ form: 'council-login' });
});

// POST /sovet/login
router.post('/login', async (req: AuthRequest, res) => {
  try {
    const { email, password } = req.body;
    if (!email || !password) {
      return res.status(400).json({ error: 'Введите email и пароль' });
    }

    const member = await db.councilMember.findUnique({ where: { email } });
    if (!member || member.status === 'blocked') {
      return res.status(401).json({ error: 'Неверные данные' });
    }

    if (!verifyPassword(password, member.passwordHash)) {
      return res.status(401).json({ error: 'Неверные данные' });
    }

    const token = signToken({
      familyId: member.id,
      email: member.email,
      role: member.role,
    });

    res.cookie('token', token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      maxAge: 7 * 24 * 60 * 60 * 1000,
    });

    res.json({ token, redirect: '/sovet/' });
  } catch (err) {
    console.error('Council login error:', err);
    res.status(500).json({ error: 'Ошибка входа' });
  }
});

// GET /sovet/vyhod
router.get('/logout', (_req: AuthRequest, res) => {
  res.clearCookie('token');
  res.json({ redirect: '/sovet/vhod' });
});

// === Задачи совета ===

// GET /sovet/zadachi
router.get('/tasks', requireAuth, async (_req: AuthRequest, res) => {
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

// POST /sovet/zadachi
router.post('/tasks', requireAuth, async (req: AuthRequest, res) => {
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

// POST /sovet/zadachi/{id}/status
router.post('/tasks/status/:id', requireAuth, async (req: AuthRequest, res) => {
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

// GET /sovet
router.get('/', requireAuth, async (_req: AuthRequest, res) => {
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

// GET /sovet/budget
router.get('/budget', requireAdmin, async (_req: AuthRequest, res) => {
  try {
    // TODO: ledger entries
    res.json({ budget: [] });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === Уведомления ===

// POST /sovet/notify
router.post('/notify', requireAdmin, async (req: AuthRequest, res) => {
  try {
    // TODO: отправка уведомления через Telegram Bot API
    res.json({ message: 'Уведомление отправлено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as councilRoutes };
