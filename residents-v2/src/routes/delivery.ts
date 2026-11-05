import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/dostavka (доска доставок)
router.get('/board', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const deliveries = await db.delivery.findMany({
      where: { status: { in: ['requested', 'open'] } },
      include: {
        requester: { include: { householdOwners: { include: { household: true } } } },
        trip: { include: { driver: true } },
      },
      orderBy: { createdAt: 'desc' },
    });
    res.json(deliveries);
  } catch (err) {
    console.error('Delivery board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/dostavka/moi
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const asRequester = await db.delivery.findMany({
      where: { requesterId: req.user!.familyId },
      orderBy: { createdAt: 'desc' },
    });
    const asCarrier = await db.delivery.findMany({
      where: { carrierId: req.user!.familyId },
      orderBy: { createdAt: 'desc' },
    });
    res.json({ asRequester, asCarrier });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/novaya
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { kind, what, place, needBy, budget, note, tripId } = req.body;
    const status = tripId ? 'requested' : 'open';

    const delivery = await db.delivery.create({
      data: {
        requesterId: req.user!.familyId,
        kind,
        what,
        place,
        needBy: needBy ? new Date(needBy) : null,
        budget: budget ? Number(budget) : null,
        note: note || null,
        tripId: tripId ? Number(tripId) : null,
        status,
      },
    });
    res.json({ id: delivery.id, message: 'Запрос создан' });
  } catch (err) {
    console.error('Delivery create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/vzyat
router.post('/take/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || delivery.carrierId !== null) {
      return res.status(400).json({ error: 'Доставка уже взята' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: {
        carrierId: req.user!.familyId,
        status: 'accepted',
        acceptedAt: new Date(),
      },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/ne-smogu
router.post('/cant-do/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || delivery.carrierId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: { carrierId: null, status: 'open' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/privez
router.post('/deliver/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || delivery.carrierId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: {
        status: 'delivered',
        deliveredAt: new Date(),
        receiptSum: req.body.receiptSum ? Number(req.body.receiptSum) : null,
      },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/rasschitalis
router.post('/settle/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || (delivery.carrierId !== req.user!.familyId && delivery.requesterId !== req.user!.familyId)) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: { status: 'settled', settledAt: new Date() },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/otmenit
router.post('/cancel/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || delivery.requesterId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: { status: 'cancelled' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dostavka/{id}/chek (загрузить чек)
router.post('/receipt/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const delivery = await db.delivery.findUnique({ where: { id: Number(req.params.id) } });
    if (!delivery || delivery.carrierId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id: delivery.id },
      data: { receiptSum: Number(req.body.receiptSum) },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as deliveryRoutes };
