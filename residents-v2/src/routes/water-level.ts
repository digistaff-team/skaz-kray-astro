import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { panel, WaterPanel } from '../services/waterLevel';

const router = Router();

/**
 * Панель «Уровень воды в Шебше» — порт WaterLevelController.
 * Отдаёт JSON: уровень, статус, диаграмма.
 */

router.get('/', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const data: WaterPanel = await panel(new Date());
    res.json(data);
  } catch (err) {
    console.error('Water level error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as waterLevelRoutes };
