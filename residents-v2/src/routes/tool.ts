import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/instrumenty
router.get('/catalog', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const tools = await db.tool.findMany({
      where: { status: { not: 'hidden' } },
      include: { family: { include: { householdOwners: { include: { household: true } } } } },
      orderBy: { createdAt: 'desc' },
    });
    res.json(tools);
  } catch (err) {
    console.error('Tool catalog error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/instrumenty/moi
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tools = await db.tool.findMany({
      where: { familyId: req.user!.familyId },
      include: { loans: true },
      orderBy: { createdAt: 'desc' },
    });
    res.json(tools);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/novyy
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { name, category, description, conditionNote, terms } = req.body;
    const tool = await db.tool.create({
      data: {
        familyId: req.user!.familyId,
        name,
        category: category || '',
        description: description || null,
        conditionNote: conditionNote || null,
        terms: terms || null,
      },
    });
    res.json({ id: tool.id, message: 'Инструмент добавлен' });
  } catch (err) {
    console.error('Tool create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/instrumenty/{id}/redaktirovat
router.post('/update/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const tool = await db.tool.findUnique({ where: { id: Number(req.params.id) } });
    if (!tool || tool.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.tool.update({
      where: { id: tool.id },
      data: req.body,
    });
    res.json(updated);
  } catch (err) {
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
