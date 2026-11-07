import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Гарды легаси (ToolController: requireHousehold('instrumenty') + RequiresSection).
router.use(requireAuth, requireHousehold, requireSection('instrumenty'));

// GET /api/tools/catalog — каталог (без скрытых), лёгкий ответ для клиента.
router.get('/catalog', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const tools = await db.tool.findMany({
      where: { status: { not: 'hidden' } },
      include: { family: { select: { name: true } } },
      orderBy: { createdAt: 'desc' },
    });
    res.json(
      tools.map((t) => ({
        id: t.id,
        name: t.name,
        category: t.category,
        description: t.description,
        conditionNote: t.conditionNote,
        terms: t.terms,
        status: t.status,
        familyId: t.familyId,
        ownerName: t.family?.name ?? '',
      })),
    );
  } catch (err) {
    console.error('Tool catalog error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/tool/mine — порт ToolController::mine: мои инструменты (+ кто
// держит), входящие займы на мои и мои займы.
router.get('/mine', async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [myTools, incoming, borrowings] = await Promise.all([
      db.tool.findMany({
        where: { familyId },
        orderBy: { createdAt: 'desc' },
        include: {
          loans: {
            where: { status: 'on_loan' },
            include: { borrower: { select: { name: true } } },
            take: 1,
          },
        },
      }),
      db.toolLoan.findMany({
        where: { tool: { familyId }, status: { in: ['requested', 'on_loan'] } },
        orderBy: { requestedAt: 'asc' },
        include: {
          tool: { select: { id: true, name: true } },
          borrower: { select: { name: true } },
        },
      }),
      db.toolLoan.findMany({
        where: { borrowerId: familyId },
        orderBy: { requestedAt: 'desc' },
        include: {
          tool: {
            select: { id: true, name: true, family: { select: { name: true } } },
          },
        },
      }),
    ]);
    res.json({
      tools: myTools.map((t) => ({
        id: t.id,
        name: t.name,
        category: t.category,
        status: t.status,
        holder: t.loans[0]?.borrower.name ?? null,
      })),
      incoming: incoming.map((l) => ({
        id: l.id,
        status: l.status,
        toolId: l.toolId,
        toolName: l.tool.name,
        borrowerName: l.borrower.name,
        dueDate: l.dueDate ? l.dueDate.toISOString().slice(0, 10) : null,
        message: l.message,
        requestedAt: l.requestedAt.toISOString(),
      })),
      borrowings: borrowings.map((l) => ({
        id: l.id,
        status: l.status,
        toolId: l.toolId,
        toolName: l.tool.name,
        ownerName: l.tool.family?.name ?? null,
        dueDate: l.dueDate ? l.dueDate.toISOString().slice(0, 10) : null,
      })),
    });
  } catch (err) {
    console.error('Tool mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/tool/:id — порт ToolController::show.
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const tool = await db.tool.findUnique({
      where: { id },
      include: { family: { select: { id: true, name: true } } },
    });
    if (!tool) return res.status(404).json({ error: 'Инструмент не найден' });

    const [active, historyRows] = await Promise.all([
      db.toolLoan.findFirst({
        where: { toolId: id, status: 'on_loan' },
        include: { borrower: { select: { name: true } } },
      }),
      db.toolLoan.findMany({
        where: { toolId: id },
        orderBy: { requestedAt: 'desc' },
        include: { borrower: { select: { name: true } } },
      }),
    ]);

    const isOwner = tool.familyId === familyId;
    const canRequest = !isOwner && tool.status === 'available' && !active;
    res.json({
      tool: {
        id: tool.id,
        name: tool.name,
        category: tool.category,
        description: tool.description,
        conditionNote: tool.conditionNote,
        terms: tool.terms,
        status: tool.status,
        familyId: tool.familyId,
      },
      ownerName: tool.family?.name ?? '',
      active: active
        ? {
            borrowerName: active.borrower.name,
            dueDate: active.dueDate ? active.dueDate.toISOString().slice(0, 10) : null,
          }
        : null,
      isOwner,
      canRequest,
      history: isOwner
        ? historyRows.map((l) => ({
            id: l.id,
            status: l.status,
            borrowerName: l.borrower.name,
            requestedAt: l.requestedAt.toISOString(),
            returnCondition: l.returnCondition,
          }))
        : [],
    });
  } catch (err) {
    console.error('Tool show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

/** Поля инструмента — порт ToolController::validate: name 2–200, category ≤80. */
function toolFields(req: AuthRequest): {
  data: {
    name: string;
    category: string;
    description: string | null;
    conditionNote: string | null;
    terms: string | null;
    status: 'available' | 'on_loan';
  } | null;
  errors: Record<string, string>;
} {
  const name = String(req.body?.name ?? '').trim().slice(0, 200);
  const category = String(req.body?.category ?? '').trim().slice(0, 80);
  const desc = String(req.body?.description ?? '').trim();
  const cond = String(req.body?.conditionNote ?? req.body?.condition_note ?? '')
    .trim()
    .slice(0, 200);
  const terms = String(req.body?.terms ?? '').trim().slice(0, 200);
  const status = (req.body?.status ?? '') === 'on_loan' ? ('on_loan' as const) : ('available' as const);
  const errors: Record<string, string> = {};
  if (name.length < 2 || name.length > 200) errors.name = 'Название: 2–200 символов.';
  if (category.length > 80) errors.category = 'Категория до 80 символов.';
  if (Object.keys(errors).length > 0) return { data: null, errors };
  return {
    data: {
      name,
      category,
      description: desc !== '' ? desc : null,
      conditionNote: cond !== '' ? cond : null,
      terms: terms !== '' ? terms : null,
      status,
    },
    errors,
  };
}

// POST /api/tool/create — порт ToolController::create (без фото).
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { data, errors } = toolFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const tool = await db.tool.create({
      data: {
        familyId: req.user!.familyId,
        name: data.name,
        category: data.category,
        description: data.description,
        conditionNote: data.conditionNote,
        terms: data.terms,
        status: data.status,
      },
    });
    res.json({ ok: true, id: tool.id, status: tool.status });
  } catch (err) {
    console.error('Tool create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/tool/update/:id — правка своего инструмента (белый список полей:
// раньше req.body уходил в update целиком — можно было подменить familyId/status).
router.post('/update/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const tool = await db.tool.findUnique({ where: { id } });
    if (!tool || tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const { data, errors } = toolFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const updated = await db.tool.update({
      where: { id },
      data: {
        name: data.name,
        category: data.category,
        description: data.description,
        conditionNote: data.conditionNote,
        terms: data.terms,
        status: data.status,
        updatedAt: new Date(),
      },
    });
    res.json({ ok: true, id: updated.id, status: updated.status });
  } catch (err) {
    console.error('Tool update error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/{id}/udalit
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tool = await db.tool.findUnique({ where: { id: Number(req.params.id) } });
    if (!tool || tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.tool.delete({ where: { id: tool.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/{id}/skryt
router.post('/toggle-hidden/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tool = await db.tool.findUnique({ where: { id: Number(req.params.id) } });
    if (!tool || tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.tool.update({
      where: { id: tool.id },
      data: { status: tool.status === 'hidden' ? 'available' : 'hidden' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/{id}/remont
router.post('/toggle-maintenance/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tool = await db.tool.findUnique({ where: { id: Number(req.params.id) } });
    if (!tool || tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.tool.update({
      where: { id: tool.id },
      data: { status: tool.status === 'maintenance' ? 'available' : 'maintenance' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/{id}/zapros
router.post('/request/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tool = await db.tool.findUnique({ where: { id: Number(req.params.id) } });
    if (!tool || tool.status !== 'available') {
      return res.status(400).json({ error: 'Инструмент недоступен' });
    }

    const loan = await db.toolLoan.create({
      data: {
        toolId: tool.id,
        borrowerId: req.user!.familyId,
        message: req.body.message || null,
        dueDate: req.body.dueDate ? new Date(req.body.dueDate) : null,
      },
    });
    res.json({ id: loan.id, message: 'Запрос отправлен' });
  } catch (err) {
    console.error('Tool request error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === ЗАЙМЫ ===

// POST /poselenie/zaymy/{id}/vydat
router.post('/loans/give/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.toolLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { tool: true },
    });

    if (!loan || loan.tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.$transaction([
      db.toolLoan.update({
        where: { id: loan.id },
        data: { status: 'on_loan', handedOutAt: new Date(), decidedAt: new Date() },
      }),
      db.tool.update({
        where: { id: loan.toolId },
        data: { status: 'on_loan' },
      }),
    ]);

    res.json({ message: 'Выдан' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zaymy/{id}/otklonit
router.post('/loans/decline/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.toolLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { tool: true },
    });

    if (!loan || loan.tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.toolLoan.update({
      where: { id: loan.id },
      data: { status: 'declined', decidedAt: new Date() },
    });
    res.json({ message: 'Отклонено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zaymy/{id}/vozvrat
router.post('/loans/return/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.toolLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { tool: true },
    });

    if (!loan || loan.tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.$transaction([
      db.toolLoan.update({
        where: { id: loan.id },
        data: {
          status: 'returned',
          returnedAt: new Date(),
          returnCondition: req.body.condition || 'ok',
          returnNote: req.body.note || null,
        },
      }),
      db.tool.update({
        where: { id: loan.toolId },
        data: { status: req.body.condition === 'broken' ? 'maintenance' : 'available' },
      }),
    ]);

    res.json({ message: 'Возврат оформлен' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/zaymy/{id}/otmenit
router.post('/loans/cancel/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.toolLoan.findUnique({ where: { id: Number(req.params.id) } });
    if (!loan || loan.borrowerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.toolLoan.update({
      where: { id: loan.id },
      data: { status: 'cancelled' },
    });
    res.json({ message: 'Отменено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as toolRoutes };
