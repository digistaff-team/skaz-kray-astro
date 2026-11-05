import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/knigi
router.get('/catalog', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const books = await db.book.findMany({
      where: { status: { not: 'hidden' } },
      include: { family: { include: { householdOwners: { include: { household: true } } } } },
      orderBy: { createdAt: 'desc' },
    });
    res.json(books);
  } catch (err) {
    console.error('Book catalog error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/knigi/moi
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const books = await db.book.findMany({
      where: { familyId: req.user!.familyId },
      include: { loans: true },
      orderBy: { createdAt: 'desc' },
    });
    res.json(books);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/novaya
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { title, author, genre, description, conditionNote } = req.body;
    const book = await db.book.create({
      data: {
        familyId: req.user!.familyId,
        title,
        author: author || '',
        genre: genre || '',
        description: description || null,
        conditionNote: conditionNote || null,
      },
    });
    res.json({ id: book.id, message: 'Книга добавлена' });
  } catch (err) {
    console.error('Book create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/{id}/redaktirovat
router.post('/update/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const book = await db.book.findUnique({ where: { id: Number(req.params.id) } });
    if (!book || book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.book.update({ where: { id: book.id }, data: req.body });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/{id}/udalit
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const book = await db.book.findUnique({ where: { id: Number(req.params.id) } });
    if (!book || book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.book.delete({ where: { id: book.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/{id}/skryt
router.post('/toggle-hidden/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const book = await db.book.findUnique({ where: { id: Number(req.params.id) } });
    if (!book || book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.book.update({
      where: { id: book.id },
      data: { status: book.status === 'hidden' ? 'available' : 'hidden' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/{id}/nedostupna
router.post('/toggle-maintenance/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const book = await db.book.findUnique({ where: { id: Number(req.params.id) } });
    if (!book || book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.book.update({
      where: { id: book.id },
      data: { status: book.status === 'maintenance' ? 'available' : 'maintenance' },
    });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi/{id}/bron
router.post('/reserve/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const book = await db.book.findUnique({ where: { id: Number(req.params.id) } });
    if (!book || book.status !== 'available') {
      return res.status(400).json({ error: 'Книга недоступна' });
    }

    const loan = await db.bookLoan.create({
      data: {
        bookId: book.id,
        borrowerId: req.user!.familyId,
        message: req.body.message || null,
        dueDate: req.body.dueDate ? new Date(req.body.dueDate) : null,
      },
    });
    res.json({ id: loan.id, message: 'Бронь запрошена' });
  } catch (err) {
    console.error('Book reserve error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi-bron/{id}/vydat
router.post('/reservations/give/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.bookLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { book: true },
    });

    if (!loan || loan.book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.$transaction([
      db.bookLoan.update({
        where: { id: loan.id },
        data: { status: 'on_loan', handedOutAt: new Date(), decidedAt: new Date() },
      }),
      db.book.update({ where: { id: loan.bookId }, data: { status: 'on_loan' } }),
    ]);

    res.json({ message: 'Выдана' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi-bron/{id}/otklonit
router.post('/reservations/decline/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.bookLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { book: true },
    });

    if (!loan || loan.book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.bookLoan.update({
      where: { id: loan.id },
      data: { status: 'declined', decidedAt: new Date() },
    });
    res.json({ message: 'Отклонено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi-bron/{id}/vozvrat
router.post('/reservations/return/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.bookLoan.findUnique({
      where: { id: Number(req.params.id) },
      include: { book: true },
    });

    if (!loan || loan.book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.$transaction([
      db.bookLoan.update({
        where: { id: loan.id },
        data: {
          status: 'returned',
          returnedAt: new Date(),
          returnCondition: req.body.condition || 'ok',
          returnNote: req.body.note || null,
        },
      }),
      db.book.update({
        where: { id: loan.bookId },
        data: { status: req.body.condition === 'broken' ? 'maintenance' : 'available' },
      }),
    ]);

    res.json({ message: 'Возврат оформлен' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/knigi-bron/{id}/otmenit
router.post('/reservations/cancel/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const loan = await db.bookLoan.findUnique({ where: { id: Number(req.params.id) } });
    if (!loan || loan.borrowerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.bookLoan.update({ where: { id: loan.id }, data: { status: 'cancelled' } });
    res.json({ message: 'Отменено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as bookRoutes };
