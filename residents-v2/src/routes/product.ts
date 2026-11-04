import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/yarmarka
router.get('/index', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const products = await db.product.findMany({
      where: { visibility: 'public', status: 'PUBLISHED' },
      include: { family: { include: { householdOwners: { include: { household: true } } } } },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    res.json(products);
  } catch (err) {
    console.error('Product index error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/yarmarka/moya
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const products = await db.product.findMany({
      where: { familyId: req.user!.familyId },
      orderBy: { createdAt: 'desc' },
    });
    res.json(products);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/yarmarka/novyy
router.get('/create', requireAuth, (_req: AuthRequest, res) => {
  res.json({ form: 'product-create' });
});

// POST /poselenie/yarmarka/novyy
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { title, description, price, unit, contact } = req.body;
    const product = await db.product.create({
      data: {
        familyId: req.user!.familyId,
        title,
        description,
        price: price || null,
        unit: unit || null,
        contact,
      },
    });
    res.json({ id: product.id, message: 'Объявление создано' });
  } catch (err) {
    console.error('Product create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/yarmarka/{id}/redaktirovat
router.post('/update/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const product = await db.product.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!product || product.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.product.update({
      where: { id: product.id },
      data: req.body,
    });

    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/yarmarka/{id}/удалить
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const product = await db.product.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!product || product.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.product.delete({ where: { id: product.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as productRoutes };
