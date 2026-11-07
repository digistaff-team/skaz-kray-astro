import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Стадии и порядок доски — порт PurchaseRepository::STATUSES + ORDER BY listBoard.
const STATUSES = ['collecting', 'ordered', 'arrived', 'done', 'cancelled'];
const STATUS_ORDER: Record<string, number> = {
  collecting: 0,
  ordered: 1,
  arrived: 2,
  done: 3,
};

// Гарды легаси (PurchaseController::guard → RequiresHousehold('zakupki')).
router.use(requireAuth, requireHousehold, requireSection('zakupki'));

type PurchaseRow = {
  id: number;
  title: string;
  category: string | null;
  unit: string;
  pricePerUnit: unknown;
  targetQty: unknown;
  deadline: Date | null;
  supplier: string | null;
  pickup: string | null;
  note: string | null;
  status: string;
  organizerId: number;
  createdAt: Date;
  organizer?: { name: string } | null;
  orders: Array<{ qty: unknown; paidAt: Date | null }>;
};

// Порт TOTALS/withProgress: собранный объём, участники, оплаченное, долг.
function viewPurchase(p: PurchaseRow) {
  const collected = p.orders.reduce((s, o) => s + Number(o.qty ?? 0), 0);
  const paid = p.orders.reduce((s, o) => (o.paidAt ? s + Number(o.qty ?? 0) : s), 0);
  return {
    id: p.id,
    title: p.title,
    category: p.category,
    unit: p.unit,
    pricePerUnit: p.pricePerUnit !== null && p.pricePerUnit !== undefined ? String(p.pricePerUnit) : null,
    targetQty: p.targetQty !== null && p.targetQty !== undefined ? String(p.targetQty) : null,
    deadline: p.deadline ? p.deadline.toISOString().slice(0, 10) : null,
    supplier: p.supplier,
    pickup: p.pickup,
    note: p.note,
    status: p.status,
    organizerId: p.organizerId,
    organizerName: p.organizer?.name ?? '',
    collectedQty: collected,
    participants: p.orders.length,
    paidQty: paid,
    unpaidCount: p.orders.filter((o) => !o.paidAt).length,
    createdAt: p.createdAt.toISOString(),
  };
}

// Порядок доски: стадия → дедлайн (без даты в конец) → id DESC (как SQL легаси).
function sortBoard<T extends { id: number; status: string; deadline: string | null }>(list: T[]): T[] {
  return list.slice().sort((a, b) => {
    const sa = STATUS_ORDER[a.status] ?? 4;
    const sb = STATUS_ORDER[b.status] ?? 4;
    if (sa !== sb) return sa - sb;
    if (a.deadline && !b.deadline) return -1;
    if (!a.deadline && b.deadline) return 1;
    if (a.deadline && b.deadline && a.deadline !== b.deadline) {
      return a.deadline < b.deadline ? -1 : 1;
    }
    return b.id - a.id;
  });
}

// GET /api/purchases/board — доска закупок (порт listBoard): ВСЕ стадии,
// с подсчётами объёма/участников/оплаты и легаси-сортировкой; фильтры — на клиенте.
// myQty — своя заявка семьи (как «вы берёте …» в легаси _card).
router.get('/board', async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [purchases, myOrders] = await Promise.all([
      db.purchase.findMany({
        include: {
          organizer: { select: { name: true } },
          orders: { select: { qty: true, paidAt: true } },
        },
        orderBy: { createdAt: 'desc' },
      }),
      db.purchaseOrder.findMany({
        where: { familyId },
        select: { purchaseId: true, qty: true },
      }),
    ]);
    const myByPurchase = new Map(myOrders.map((o) => [o.purchaseId, String(o.qty)]));
    res.json(
      sortBoard(
        purchases.map((p) => ({ ...viewPurchase(p), myQty: myByPurchase.get(p.id) ?? null })),
      ),
    );
  } catch (err) {
    console.error('Purchase board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/purchases/mine — «Мои закупки» (порт mine): свои как организатор
// (с прогрессом сбора) + свои заявки участником.
router.get('/mine', async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [organized, asMember] = await Promise.all([
      db.purchase.findMany({
        where: { organizerId: familyId },
        include: { orders: { select: { qty: true, paidAt: true } } },
        orderBy: { createdAt: 'desc' },
      }),
      db.purchaseOrder.findMany({
        where: { familyId },
        include: { purchase: { select: { id: true, title: true, status: true, unit: true } } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);
    res.json({
      asOrganizer: organized.map(viewPurchase),
      asMember: asMember.map((o) => ({
        id: o.id,
        purchaseId: o.purchaseId,
        purchaseTitle: o.purchase.title,
        purchaseStatus: o.purchase.status,
        unit: o.purchase.unit,
        qty: String(o.qty),
        note: o.note,
        paidAt: o.paidAt ? o.paidAt.toISOString() : null,
        createdAt: o.createdAt.toISOString(),
      })),
    });
  } catch (err) {
    console.error('Purchase mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/purchases/:id — порт show: карточка с прогрессом, заявки
// участников и текущая заявка семьи.
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const purchase = await db.purchase.findUnique({
      where: { id },
      include: {
        organizer: { select: { name: true } },
        orders: {
          include: { family: { select: { name: true } } },
          orderBy: { createdAt: 'asc' },
        },
      },
    });
    if (!purchase) return res.status(404).json({ error: 'Закупка не найдена' });

    const myOrder = purchase.orders.find((o) => o.familyId === familyId) || null;
    res.json({
      purchase: viewPurchase(purchase),
      orders: purchase.orders.map((o) => ({
        id: o.id,
        familyId: o.familyId,
        familyName: o.family?.name ?? '',
        qty: String(o.qty),
        note: o.note,
        paidAt: o.paidAt ? o.paidAt.toISOString() : null,
        createdAt: o.createdAt.toISOString(),
      })),
      myOrder: myOrder ? { qty: String(myOrder.qty), note: myOrder.note } : null,
      isOwner: purchase.organizerId === familyId,
    });
  } catch (err) {
    console.error('Purchase show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

/** Число (целое или с копейками) — порт PurchaseController::num. */
function numField(raw: string): string | null {
  const v = raw.replace(/[ ]/g, '').replace(/,/g, '.');
  return /^\d{1,8}(\.\d{1,2})?$/.test(v) ? v : null;
}

/** Поля закупки — порт PurchaseController::validate. */
function purchaseFields(req: AuthRequest): {
  data: {
    title: string;
    category: string | null;
    unit: string;
    pricePerUnit: string | null;
    targetQty: string | null;
    deadline: string | null;
    supplier: string | null;
    pickup: string | null;
    note: string | null;
  } | null;
  errors: Record<string, string>;
} {
  const title = String(req.body?.title ?? '').trim();
  const category = String(req.body?.category ?? '').trim().slice(0, 80);
  const unit = String(req.body?.unit ?? '').trim();
  const priceRaw = String(req.body?.pricePerUnit ?? '').trim();
  const targetRaw = String(req.body?.targetQty ?? '').trim();
  const deadline = String(req.body?.deadline ?? '').trim();
  const supplier = String(req.body?.supplier ?? '').trim().slice(0, 200);
  const pickup = String(req.body?.pickup ?? '').trim().slice(0, 200);
  const note = String(req.body?.note ?? '').trim().slice(0, 500);

  const errors: Record<string, string> = {};
  if (title.length < 2 || title.length > 200) errors.title = 'Название: 2–200 символов.';
  if (unit === '' || unit.length > 20) errors.unit = 'Укажите единицу измерения (до 20 символов).';
  let pricePerUnit: string | null = null;
  if (priceRaw !== '') {
    pricePerUnit = numField(priceRaw);
    if (pricePerUnit === null) errors.price_per_unit = 'Цена — число, например 450 или 450.50.';
  }
  let targetQty: string | null = null;
  if (targetRaw !== '') {
    targetQty = numField(targetRaw);
    if (targetQty === null) errors.target_qty = 'Цель — число в выбранных единицах.';
  }
  if (deadline !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(deadline)) {
    errors.deadline = 'Дата в формате ГГГГ-ММ-ДД.';
  }
  if (Object.keys(errors).length > 0) return { data: null, errors };
  return {
    data: {
      title,
      category: category !== '' ? category : null,
      unit,
      pricePerUnit,
      targetQty,
      deadline: deadline !== '' ? deadline : null,
      supplier: supplier !== '' ? supplier : null,
      pickup: pickup !== '' ? pickup : null,
      note: note !== '' ? note : null,
    },
    errors,
  };
}

// POST /api/purchases/create — порт PurchaseController::create (без фото и
// уведомлений участников). Статус по умолчанию — сбор (schema default).
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { data, errors } = purchaseFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const purchase = await db.purchase.create({
      data: {
        organizerId: req.user!.familyId,
        title: data.title,
        category: data.category,
        unit: data.unit,
        pricePerUnit: data.pricePerUnit,
        targetQty: data.targetQty,
        deadline: data.deadline ? new Date(data.deadline) : null,
        supplier: data.supplier,
        pickup: data.pickup,
        note: data.note,
      },
    });
    res.json({ ok: true, id: purchase.id, status: purchase.status });
  } catch (err) {
    console.error('Purchase create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/purchases/order/:id — заявка участника (upsert: правка состава
// разрешена, пока идёт сбор). qty обязателен и должен быть положительным числом.
router.post('/order/:id', async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.status !== 'collecting') {
      return res.status(400).json({ ok: false, error: 'Сбор закрыт' });
    }
    const qtyRaw = String(req.body?.qty ?? '').trim().replace(/[ ]/g, '').replace(/,/g, '.');
    const qty = numField(qtyRaw);
    if (qty === null || Number(qty) <= 0) {
      return res
        .status(400)
        .json({ ok: false, errors: { qty: 'Укажите количество — положительное число.' } });
    }

    // existed — для сообщения «записаны» vs «заявка обновлена» (порт flash легаси).
    const key = { purchaseId: purchase.id, familyId: req.user!.familyId };
    const existed = (await db.purchaseOrder.findUnique({ where: { purchaseId_familyId: key } })) !== null;
    const order = await db.purchaseOrder.upsert({
      where: {
        purchaseId_familyId: key,
      },
      create: {
        purchaseId: purchase.id,
        familyId: req.user!.familyId,
        qty,
        note: String(req.body?.note ?? '').trim().slice(0, 500) || null,
      },
      update: {
        qty,
        note: String(req.body?.note ?? '').trim().slice(0, 500) || null,
      },
    });
    res.json({ ok: true, id: order.id, existed });
  } catch (err) {
    console.error('Purchase order error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/purchases/update/:id — правка закупки организатором: валидация
// как в create, белый список полей (статус — только через /status).
router.post('/update/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const purchase = await db.purchase.findUnique({ where: { id } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const { data, errors } = purchaseFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const updated = await db.purchase.update({
      where: { id },
      data: {
        title: data.title,
        category: data.category,
        unit: data.unit,
        pricePerUnit: data.pricePerUnit,
        targetQty: data.targetQty,
        deadline: data.deadline ? new Date(data.deadline) : null,
        supplier: data.supplier,
        pickup: data.pickup,
        note: data.note,
        updatedAt: new Date(),
      },
    });
    res.json({ ok: true, id: updated.id });
  } catch (err) {
    console.error('Purchase update error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/purchases/status/:id — смена стадии организатором (порт setStatus):
// только известные стадии, без уведомлений участников (PurchaseNotify — позже).
router.post('/status/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const purchase = await db.purchase.findUnique({ where: { id } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const status = String(req.body?.status ?? '');
    if (!STATUSES.includes(status)) {
      return res.status(400).json({ ok: false, error: 'Неизвестная стадия' });
    }
    if (status === purchase.status) {
      return res.status(400).json({ ok: false, reason: 'same', error: 'Стадия не изменилась' });
    }
    const updated = await db.purchase.update({
      where: { id },
      data: { status, updatedAt: new Date() },
    });
    res.json({ ok: true, id: updated.id, status: updated.status });
  } catch (err) {
    console.error('Purchase status error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/purchases/withdraw/:id — выйти из закупки (порт
// PurchaseOrderController::withdraw): только пока идёт состав, своя заявка.
router.post('/withdraw/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const purchase = await db.purchase.findUnique({ where: { id } });
    if (!purchase) return res.status(404).json({ ok: false, error: 'Закупка не найдена' });
    if (purchase.status !== 'collecting') {
      return res
        .status(400)
        .json({ ok: false, error: 'Сбор закрыт — снять заявку уже нельзя, напишите организатору.' });
    }
    const order = await db.purchaseOrder.findUnique({
      where: { purchaseId_familyId: { purchaseId: id, familyId: req.user!.familyId } },
    });
    if (order) await db.purchaseOrder.delete({ where: { id: order.id } });
    res.json({ ok: true, removed: order !== null });
  } catch (err) {
    console.error('Purchase withdraw error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/purchases/pay/:id/:orderId — отметить/снять оплату участника (порт
// togglePaid): только организатор; заявка обязана принадлежать закупке.
router.post('/pay/:id/:orderId', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const orderId = Number(req.params.orderId);
    const purchase = await db.purchase.findUnique({ where: { id } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const order = await db.purchaseOrder.findUnique({ where: { id: orderId } });
    if (!order || order.purchaseId !== id) {
      return res.status(404).json({ error: 'Заявка не найдена' });
    }
    // Была не отмечена — отмечаем, и наоборот (паритет с легаси togglePaid).
    const paid = order.paidAt === null;
    const updated = await db.purchaseOrder.update({
      where: { id: orderId },
      data: { paidAt: paid ? new Date() : null, updatedAt: new Date() },
    });
    res.json({ ok: true, paid: updated.paidAt !== null });
  } catch (err) {
    console.error('Purchase pay toggle error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zakupki/{id}/udalit — с фото (сироты в images, как в
// легаси: deleteImageFiles + ImageRepository::deleteFor).
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const purchase = await db.purchase.findUnique({ where: { id: Number(req.params.id) } });
    if (!purchase || purchase.organizerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    // Заявки участников удаляются каскадом (schema: onDelete: Cascade).
    await db.$transaction([
      db.image.deleteMany({ where: { ownerType: 'purchase', ownerId: purchase.id } }),
      db.purchase.delete({ where: { id: purchase.id } }),
    ]);
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as purchaseRoutes };
