import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';
import { enabledSections } from '../lib/sections';

const router = Router();

// GET /api/cabinet — данные домашнего экрана приложения (главная /poselenie/):
// подпись под «Сказочный Край» (название поместья, иначе имя семьи — порт
// AppController::home), факт привязанного поместья (нет → страница выбора
// поместья, как первый вход в легаси) и включённые разделы для плиток.
router.get('/', requireAuth, async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [family, owner, sections] = await Promise.all([
      db.family.findUnique({
        where: { id: familyId },
        select: {
          id: true,
          name: true,
          role: true,
          status: true,
          telegramUsername: true,
        },
      }),
      db.householdOwner.findUnique({
        where: { familyId },
        select: { household: { select: { estateName: true } } },
      }),
      enabledSections(),
    ]);

    if (!family) return res.status(404).json({ error: 'Не найдено' });

    const estateName = (owner?.household.estateName ?? '').trim();
    res.json({ family, estateName, hasHousehold: owner !== null, sections });
  } catch (err) {
    console.error('Cabinet error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as cabinetRoutes };

