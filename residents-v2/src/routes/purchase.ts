import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/zakupki (доска закупок)
router.get('/board', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const purchases = await db.purchase.findMany({
      where: { status: { in: ['collecting', 'ordered'] } },
      include: {
        organizer: { include: { householdOwners: { include: { household: true } } } },
        orders: { include: { family: true } },
      },
      orderBy: { createdAt: 'desc' },
    });
    res.json(purchases);
  } catch (err) {
    console.error('Purchase board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/zakupki/moi
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const asOrganizer = await db.purchase.findMany({
      where: { organizerId: req.user!.familyId },
      include: { orders: { include: { family: true } } },
      orderBy: { createdAt: 'desc' },
    });
    const asMember = await db.purchaseOrder.findMany({
      where: { familyId: req.user!.familyId },
      include: { purchase: true },
      orderBy: { createdAt: 'desc' },
    });
    res.json({ asOrganizer, asMember });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/novaya
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { title, category, unit, pricePerUnit, targetQty, deadline, supplier, pickup, note } = req.body;
    const purchase = await db.purchase.create({
      data: {
        organizerId: req.user!.familyId,
        title,
        category: category || null,
        unit: unit || 'шт',
        pricePerUnit: pricePerUnit ? Number(pricePerUnit) : null,
        targetQty: targetQty ? Number(targetQty) : null,
        deadline: deadline ? new Date(deadline) : null,
        supplier: supplier || null,
        pickup: pickup || null,
        note: note || null,
      },
    });
    res.json({ id: purchase.id, message: 'Закупка создана' });
  } catch (err) {
    console.error('Purchase create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/{id}/uchastvovat
router.post('/order/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.status !== 'collecting') {
      return res.status(400).json({ error: 'Сбор закрыт' });
    }

    const order = await db.purchaseOrder.upsert({
      where: {
        purchaseId_familyId: { purchaseId: purchase.id, familyId: req.user!.familyId },
      },
      create: {
        purchaseId: purchase.id,
        familyId: req.user!.familyId,
        qty: Number(req.body.qty),
        note: req.body.note || null,
      },
      update: {
        qty: Number(req.body.qty),
        note: req.body.note || null,
      },
    });
    res.json({ id: order.id, message: 'Заявка оформлена' });
  } catch (err) {
    console.error('Purchase order error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/{id}/redaktirovat
router.post('/update/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.purchase.update({
      where: { id: purchase.id },
      data: req.body,
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/{id}/stadiya
router.post('/status/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.purchase.update({
      where: { id: purchase.id },
      data: { status: req.body.status },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/{id}/udalit
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.purchase.delete({ where: { id: purchase.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as purchaseRoutes };
