import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Единицы измерения — порт ProductController::UNITS (подсказка в форме).
const UNITS = [
  'шт.', 'час', 'кг.', 'г.', 'л.', 'м.', 'м²', 'м³', 'день', 'комплект',
  'упаковка', 'банка', 'пучок', 'услуга',
];

// Гарды легаси: requireHousehold('yarmarka') = RequiresSection + вход + поместье.
router.use(requireAuth, requireHousehold, requireSection('yarmarka'));

// GET /api/products/index — витрина «Товары и услуги соседей» (порт
// ProductRepository::listAvailable): ВСЕ опубликованные независимо от
// visibility (public товар после модерации тоже published).
router.get('/index', async (_req: AuthRequest, res) => {
  try {
    const products = await db.product.findMany({
      where: { status: 'published' },
      include: { family: { select: { name: true } } },
      orderBy: [{ publishedAt: 'desc' }, { createdAt: 'desc' }],
      take: 60,
    });
    res.json(
      products.map((p) => ({
        id: p.id,
        title: p.title,
        description: p.description,
        price: p.price,
        unit: p.unit,
        contact: p.contact,
        visibility: p.visibility,
        status: p.status,
        familyId: p.familyId,
        ownerName: p.family?.name ?? '',
        publishedAt: p.publishedAt ? p.publishedAt.toISOString() : null,
      })),
    );
  } catch (err) {
    console.error('Product index error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/products/mine — «Моя витрина» (порт listByFamily): свои товары
// любого статуса, включая отклонённые (с причиной) и ожидающие проверки.
router.get('/mine', async (req: AuthRequest, res) => {
  try {
    const products = await db.product.findMany({
      where: { familyId: req.user!.familyId },
      orderBy: { createdAt: 'desc' },
    });
    res.json(
      products.map((p) => ({
        id: p.id,
        title: p.title,
        description: p.description,
        price: p.price,
        unit: p.unit,
        contact: p.contact,
        visibility: p.visibility,
        status: p.status,
        rejectReason: p.rejectReason,
        familyId: p.familyId,
        publishedAt: p.publishedAt ? p.publishedAt.toISOString() : null,
        createdAt: p.createdAt.toISOString(),
      })),
    );
  } catch (err) {
    console.error('Product mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

/** Цена — только цифры в рублях (копейки через точку/запятую) — порт num(). */
function numPrice(raw: string): string | null {
  const v = raw.replace(/[ ]/g, '').replace(/,/g, '.');
  return /^\d{1,8}(\.\d{1,2})?$/.test(v) ? v : null;
}

/** Поля объявления — порт ProductController::validate. */
function productFields(req: AuthRequest): {
  data: {
    title: string;
    description: string;
    price: string | null;
    unit: string | null;
    contact: string;
    visibility: 'residents' | 'public';
  } | null;
  errors: Record<string, string>;
} {
  const title = String(req.body?.title ?? '').trim();
  const desc = String(req.body?.description ?? '').trim();
  const priceRaw = String(req.body?.price ?? '').trim();
  const unit = String(req.body?.unit ?? '').trim();
  const contact = String(req.body?.contact ?? '').trim();
  const visibility = req.body?.visibility === 'public' ? 'public' : 'residents';
  const errors: Record<string, string> = {};
  if (title.length < 2 || title.length > 200) errors.title = 'Название: 2–200 символов.';
  if (desc === '') errors.description = 'Опишите товар или услугу.';
  if (contact.length < 3 || contact.length > 200) errors.contact = 'Укажите, как с вами связаться.';
  let price: string | null = priceRaw === '' ? null : numPrice(priceRaw);
  if (priceRaw !== '' && price === null) {
    errors.price = 'Цена — число в рублях, например 500 или 500.50.';
    price = null;
  }
  if (Object.keys(errors).length > 0) return { data: null, errors };
  return {
    data: {
      title,
      description: desc,
      price,
      // Единица осмысленна только вместе с ценой; чужое значение отбрасываем.
      unit: price !== null && UNITS.includes(unit) ? unit : null,
      contact,
      visibility,
    },
    errors,
  };
}

// GET /api/products/:id — порт ProductController::show: соседям видно
// опубликованное, свой товар владелец открывает в любом статусе.
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const product = await db.product.findUnique({
      where: { id },
      include: { family: { select: { name: true } } },
    });
    if (!product || (product.status !== 'published' && product.familyId !== familyId)) {
      return res.status(404).json({ error: 'Товар не найден' });
    }
    res.json({
      product: {
        id: product.id,
        title: product.title,
        description: product.description,
        price: product.price,
        unit: product.unit,
        contact: product.contact,
        visibility: product.visibility,
        status: product.status,
        rejectReason: product.rejectReason,
        familyId: product.familyId,
        publishedAt: product.publishedAt ? product.publishedAt.toISOString() : null,
      },
      ownerName: product.family?.name ?? '',
      isOwner: product.familyId === familyId,
    });
  } catch (err) {
    console.error('Product show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/products/create — порт ProductController::create: public → на
// проверку (pending), residents → публикуется сразу (без фото и анонса).
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { data, errors } = productFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const isPublic = data.visibility === 'public';
    const now = new Date();
    const product = await db.product.create({
      data: {
        familyId: req.user!.familyId,
        title: data.title,
        description: data.description,
        price: data.price,
        unit: data.unit,
        contact: data.contact,
        visibility: data.visibility,
        status: isPublic ? 'pending' : 'published',
        publishedAt: isPublic ? null : now,
        updatedAt: now,
      },
    });
    res.json({ ok: true, id: product.id, status: product.status });
  } catch (err) {
    console.error('Product create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/products/update/:id — правка своего объявления: валидация как в
// create; смена visibility пересчитывает статус (public → снова на проверку),
// белый список полей закрывает подмену familyId/status чужим телом запроса.
router.post('/update/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const product = await db.product.findUnique({ where: { id } });
    if (!product || product.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const { data, errors } = productFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const isPublic = data.visibility === 'public';
    const now = new Date();
    const updated = await db.product.update({
      where: { id },
      data: {
        title: data.title,
        description: data.description,
        price: data.price,
        unit: data.unit,
        contact: data.contact,
        visibility: data.visibility,
        status: isPublic ? 'pending' : 'published',
        rejectReason: null,
        publishedAt: isPublic ? null : now,
        updatedAt: now,
      },
    });
    res.json({ ok: true, id: updated.id, status: updated.status });
  } catch (err) {
    console.error('Product update error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/yarmarka/{id}/удалить — вместе с фото: строки в images
// (полиморфные, FK нет) иначе остаются сиротами — паритет с deleteImageFiles
// + ImageRepository::deleteFor легаси.
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const product = await db.product.findUnique({
      where: { id },
    });

    if (!product || product.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.$transaction([
      db.image.deleteMany({ where: { ownerType: 'product', ownerId: id } }),
      db.product.delete({ where: { id: product.id } }),
    ]);
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as productRoutes };
