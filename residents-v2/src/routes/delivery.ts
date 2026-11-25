import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Гарды легаси (DeliveryController: requireHousehold('dostavka') + RequiresSection).
router.use(requireAuth, requireHousehold, requireSection('dostavka'));

/** 'YYYY-MM-DD' из Date (need_by приходит как полночь UTC). */
function ymd(d: Date): string { return d.toISOString().slice(0, 10); }

function dLabel(kind: string): string {
  return kind === 'buy' ? 'Купить' : 'Забрать';
}

function deliveryOut(d: any, extra: Record<string, unknown> = {}) {
  return {
    id: d.id,
    kind: d.kind,
    kindLabel: dLabel(d.kind),
    what: d.what,
    place: d.place,
    needBy: d.needBy ? ymd(new Date(d.needBy)) : null,
    budget: d.budget ? Number(d.budget) : null,
    pickupCode: d.pickupCode,
    note: d.note,
    receiptSum: d.receiptSum ? Number(d.receiptSum) : null,
    status: d.status,
    createdAt: d.createdAt,
    acceptedAt: d.acceptedAt,
    deliveredAt: d.deliveredAt,
    settledAt: d.settledAt,
    tripId: d.tripId,
    ...extra,
  };
}

/** Название семьи по данным легаси (см. trip.ts). */
function famName(family: any): string {
  const head = (family?.households ?? [])[0];
  return (head?.name as string) || (family?.name as string) || 'Житель';
}

// GET /api/deliveries/board — доска доставок (порт listBoard).
// Фильтр: ?kind=buy или ?kind=pickup.
router.get('/board', async (req: AuthRequest, res) => {
  try {
    const kind = String(req.query.kind ?? '').trim();
    if (!['', 'buy', 'pickup'].includes(kind)) return res.status(400).json({ error: 'Недопустимый фильтр kind' });

    const today = new Date(); today.setUTCHours(0, 0, 0, 0);
    const where: any = { status: 'open', needBy: { gte: today } };

    const deliveries = await db.delivery.findMany({
      where,
      include: {
        requester: { include: { householdOwners: { include: { household: true } } } },
        trip: { select: { id: true, origin: true, destination: true, tripDate: true, driverId: true } },
      },
      orderBy: [
        { needBy: 'asc' },
        { createdAt: 'asc' },
        { id: 'asc' },
      ],
    });

    const term = kind;
    const rows = deliveries
      .filter((d) => !term || d.kind === term)
      .map((d) => deliveryOut(d, {
        reqName: famName(d.requester),
        trip: d.trip
          ? { id: d.trip.id, origin: d.trip.origin, destination: d.trip.destination, tripDate: ymd(d.trip.tripDate) }
          : null,
      }));

    res.json(rows);
  } catch (err) {
    console.error('Delivery board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/deliveries/mine — «Мои доставки»: как заказчик и как исполнитель.
router.get('/mine', async (req: AuthRequest, res) => {
  const familyId = req.user!.familyId;
  try {
    const [asRequester, asCarrier] = await Promise.all([
      db.delivery.findMany({
        where: { requesterId: familyId },
        include: { carrier: { include: { householdOwners: { include: { household: true } } } } },
        orderBy: { createdAt: 'desc' },
      }),
      db.delivery.findMany({
        where: { carrierId: familyId, status: { in: ['accepted', 'delivered', 'settled'] } },
        include: { requester: { include: { householdOwners: { include: { household: true } } } } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);

    res.json({
      asRequester: asRequester.map((d) =>
        deliveryOut(d, { carrier: d.carrier ? famName(d.carrier) : null })),
      asCarrier: asCarrier.map((d) => deliveryOut(d, { reqName: famName(d.requester) })),
    });
  } catch (err) {
    console.error('Delivery mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/deliveries/:id — карточка заявки (детально с контактами сторон).
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (!Number.isInteger(id) || id <= 0) return res.status(404).json({ error: 'Не найдено' });

    const delivery = await db.delivery.findUnique({
      where: { id },
      include: {
        requester: { include: { householdOwners: { include: { household: true } } } },
        carrier: { include: { householdOwners: { include: { household: true } } } },
        trip: { include: { driver: { include: { householdOwners: { include: { household: true } } } } } },
      },
    });
    if (!delivery) return res.status(404).json({ error: 'Не найдено' });

    const familyId = req.user!.familyId;
    const isRequester = delivery.requesterId === familyId;
    const isCarrier = delivery.carrierId !== null && delivery.carrierId === familyId;
    const isDriver = delivery.tripId !== null && delivery.trip?.driverId === familyId;

    // Политики видимости: открытая — любой, остальные только стороны.
    if (delivery.status !== 'open' && !isRequester && !isCarrier && !isDriver) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    // Контакты/код получения — только стороны.
    const seesPrivate = isRequester || isCarrier;

    res.json(deliveryOut(delivery, {
      reqName: famName(delivery.requester),
      reqTg: delivery.requester.telegramUsername,
      carrierId: delivery.carrierId,
      carrierName: delivery.carrier ? famName(delivery.carrier) : null,
      carrierTg: delivery.carrier?.telegramUsername,
      trip: delivery.trip
        ? {
            id: delivery.trip.id,
            origin: delivery.trip.origin,
            destination: delivery.trip.destination,
            tripDate: ymd(delivery.trip.tripDate),
            driverName: delivery.trip.driver ? famName(delivery.trip.driver) : null,
          }
        : null,
      isRequester,
      isCarrier,
      isDriver,
      seesPrivate,
    }));
  } catch (err) {
    console.error('Delivery show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/create — новая заявка на доставку.
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { kind, what, place, needBy, budget, note, tripId } = req.body;
    const k = String(kind ?? '').trim();
    const w = String(what ?? '').trim();
    const p = String(place ?? '').trim();
    if (!k || !w || !p) return res.status(400).json({ error: 'Укажите что нужно, где и для кого' });
    if (w.length > 1000 || p.length > 160) return res.status(400).json({ error: 'Слишком длинное описание' });
    if (!['buy', 'pickup'].includes(k)) return res.status(400).json({ error: 'Неизвестный тип' });

    let tripIdNum: number | null = null;
    if (tripId) {
      const tid = Number(tripId);
      if (!Number.isInteger(tid) || tid <= 0) return res.status(400).json({ error: 'Неверная поездка' });
      const trip = await db.trip.findUnique({ where: { id: tid } });
      if (!trip || trip.status !== 'active') return res.status(400).json({ error: 'Поездка недоступна' });
      const today = new Date(); today.setUTCHours(0, 0, 0, 0);
      if (trip.tripDate < today) return res.status(400).json({ error: 'Поездка прошла' });
      tripIdNum = tid;
    }

    const nb = needBy ? new Date(String(needBy) + 'T00:00:00.000Z') : null;
    if (nb && isNaN(nb.getTime())) return res.status(400).json({ error: 'Неверная дата' });

    const delivery = await db.delivery.create({
      data: {
        requesterId: req.user!.familyId,
        kind: k,
        what: w.slice(0, 1000),
        place: p.slice(0, 160),
        needBy: nb,
        budget: budget ? Math.max(0, Math.floor(Number(budget) || 0)) : null,
        note: note ? String(note).slice(0, 500) : null,
        tripId: tripIdNum,
        status: tripIdNum !== null ? 'requested' : 'open',
      },
    });
    res.json({ id: delivery.id, message: 'Заявка создана' });
  } catch (err) {
    console.error('Delivery create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/vzyat — взять заявку (из статуса open в accepted).
router.post('/vzyat/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery) return res.status(404).json({ error: 'Не найдено' });
    if (delivery.carrierId !== null) return res.status(400).json({ error: 'Доставка уже взята' });
    if (delivery.status !== 'open') return res.status(400).json({ error: 'Заявка уже обработана' });

    const updated = await db.delivery.update({
      where: { id, status: 'open' },
      data: { carrierId: familyId, status: 'accepted', acceptedAt: new Date() },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery take error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/ne-smogu — «Не смогу»: отменить взятую заявку.
// Если была к поездке — возвращаем на доску; если просто открытая — тоже.
router.post('/ne-smogu/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery || delivery.carrierId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (delivery.status !== 'accepted') return res.status(400).json({ error: 'Заявка уже обработана' });

    const updated = await db.delivery.update({
      where: { id, carrierId: familyId, status: 'accepted' },
      data: { carrierId: null, status: 'open', tripId: null, acceptedAt: null },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery decline error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/privez — исполнитель отметил доставку.
router.post('/privez/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery || delivery.carrierId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (delivery.status !== 'accepted') return res.status(400).json({ error: 'Заявка уже обработана' });

    const updated = await db.delivery.update({
      where: { id, carrierId: familyId, status: 'accepted' },
      data: { status: 'delivered', deliveredAt: new Date() },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery deliver error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/chek — загрузить сумму чека (исполнитель).
router.post('/chek/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery || delivery.carrierId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (delivery.status !== 'delivered') return res.status(400).json({ error: 'Уже не доставка' });

    const sum = Math.max(0, Math.floor(Number(req.body.receiptSum) || 0));
    const updated = await db.delivery.update({
      where: { id },
      data: { receiptSum: sum },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery receipt error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/rasschitalis — «Получил, рассчитались».
router.post('/rasschitalis/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery) return res.status(404).json({ error: 'Не найдено' });
    if (delivery.status !== 'delivered') return res.status(400).json({ error: 'Уже не доставка' });
    if (delivery.requesterId !== familyId && delivery.carrierId !== familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.delivery.update({
      where: { id },
      data: { status: 'settled', settledAt: new Date() },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery settle error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/deliveries/:id/otmenit — заказчик отменяет заявку.
router.post('/otmenit/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const delivery = await db.delivery.findUnique({ where: { id } });
    if (!delivery || delivery.requesterId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (!['open', 'requested', 'accepted'].includes(delivery.status)) {
      return res.status(400).json({ error: 'Заявку уже не отменить' });
    }

    const updated = await db.delivery.update({
      where: { id, status: delivery.status },
      data: { status: 'cancelled' },
    });
    res.json(deliveryOut(updated));
  } catch (err) {
    console.error('Delivery cancel error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as deliveryRoutes };
