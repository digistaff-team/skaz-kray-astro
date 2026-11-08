import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { buildMyTasks } from '../services/myTasks';

const router = Router();

/**
 * «Мои дела» — всё, что ждёт действия жителя.
 * Порт TasksController. Собирается из текущих статусов, см. MyTasks.
 */

router.get('/', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tasks = await buildMyTasks(
      req.user!.familyId,
      req.user!.role === 'editor' || req.user!.role === 'admin',
      new Date().toISOString().split('T')[0],
    );
    res.json(tasks);
  } catch (err) {
    console.error('Tasks error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as tasksRoutes };
