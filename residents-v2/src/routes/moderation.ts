import { Router } from 'express';
import { requireEditor, requireAdmin, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';
import { hashPassword } from '../lib/crypto';

const router = Router();

router.use(requireEditor);

// GET /poselenie/moderation
router.get('/', async (_req: AuthRequest, res) => {
  try {
    const [families, entries, products] = await Promise.all([
      db.family.findMany({ where: { status: 'pending' }, orderBy: { createdAt: 'asc' } }),
      db.diaryEntry.findMany({ where: { status: 'pending' }, include: { family: true } }),
      db.product.findMany({ where: { status: 'pending' }, include: { family: true } }),
    ]);
    res.json({ families, entries, products });
  } catch (err) {
    console.error('Moderation index error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/moderation/razdely
router.get('/sections', requireAdmin, async (_req: AuthRequest, res) => {
  try {
    const sections = await db.appSection.findMany();
    res.json(sections);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/razdely/pereklyuchit
router.post('/sections/toggle', requireAdmin, async (req: AuthRequest, res) => {
  try {
    const { sectionKey, enabled } = req.body;
    const section = await db.appSection.upsert({
      where: { sectionKey },
      update: { enabled },
      create: { sectionKey, enabled },
    });
    res.json(section);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/family/approve
router.post('/family/approve', async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'active', approvedAt: new Date() },
    });
    res.json(family);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/family/reject
router.post('/family/reject', async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'blocked' },
    });
    res.json(family);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/family/reset-password
router.post('/family/reset-password', requireAdmin, async (req: AuthRequest, res) => {
  try {
    const { familyId, newPassword } = req.body;
    await db.family.update({
      where: { id: Number(familyId) },
      data: { passwordHash: hashPassword(newPassword) },
    });
    res.json({ message: 'Пароль сброшен' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/entry/approve
router.post('/entry/approve', async (req: AuthRequest, res) => {
  try {
    const entry = await db.diaryEntry.update({
      where: { id: Number(req.body.entryId) },
      data: {
        status: 'published',
        publishedAt: new Date(),
        visibility: req.body.public ? 'public' : 'residents',
      },
    });
    res.json(entry);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/entry/reject
router.post('/entry/reject', async (req: AuthRequest, res) => {
  try {
    const entry = await db.diaryEntry.update({
      where: { id: Number(req.body.entryId) },
      data: { status: 'rejected', rejectReason: req.body.reason || '' },
    });
    res.json(entry);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/product/approve
router.post('/product/approve', async (req: AuthRequest, res) => {
  try {
    const product = await db.product.update({
      where: { id: Number(req.body.productId) },
      data: { status: 'published', publishedAt: new Date() },
    });
    res.json(product);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moderation/product/reject
router.post('/product/reject', async (req: AuthRequest, res) => {
  try {
    const product = await db.product.update({
      where: { id: Number(req.body.productId) },
      data: { status: 'rejected', rejectReason: req.body.reason || '' },
    });
    res.json(product);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as moderationRoutes };
