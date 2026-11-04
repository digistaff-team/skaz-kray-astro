import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/sosedi (справочник)
router.get('/directory', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const owners = await db.householdOwner.findMany({
      include: {
        household: { include: { family: true } },
        family: true,
      },
    });
    res.json(owners);
  } catch (err) {
    console.error('Directory error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/sosedi/approve (модерация семей)
router.post('/approve', requireAuth, async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'ACTIVE', approvedAt: new Date() },
    });
    res.json(family);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/sosedi/reject
router.post('/reject', requireAuth, async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'BLOCKED' },
    });
    res.json(family);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as residentsRoutes };
