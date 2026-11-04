import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie (главная — кабинет жителя)
router.get('/', requireAuth, async (req: AuthRequest, res) => {
  try {
    const family = await db.family.findUnique({
      where: { id: req.user!.familyId },
      include: {
        householdOwners: { include: { household: true } },
        diaryEntries: { take: 5, orderBy: { createdAt: 'desc' } },
        products: { take: 5, orderBy: { createdAt: 'desc' } },
        toolLoansAsBorrow: { where: { status: { in: ['ON_LOAN', 'REQUESTED'] } }, take: 5 },
        bookLoansAsBorrow: { where: { status: { in: ['ON_LOAN', 'REQUESTED'] } }, take: 5 },
        joinRequests: true,
      },
    });

    if (!family) return res.status(404).json({ error: 'Не найдено' });

    res.json({ family });
  } catch (err) {
    console.error('Cabinet error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as cabinetRoutes };
