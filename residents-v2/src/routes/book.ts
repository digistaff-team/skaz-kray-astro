import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Гарды легаси (BookController::guard → RequiresHousehold + RequiresSection):
// раздел закрыт без поместья и когда выключен админом. requireAuth здесь
// нужен до requireHousehold (он читает familyId), дальше не дублируется.
router.use(requireAuth, requireHousehold, requireSection('knigi'));

// GET /api/books/catalog — каталог (без скрытых), лёгкий ответ для клиента.
router.get('/catalog', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const books = await db.book.findMany({
      where: { status: { not: 'hidden' } },
      include: { family: { select: { name: true } } },
      orderBy: { createdAt: 'desc' },
    });
    res.json(
      books.map((b) => ({
        id: b.id,
        title: b.title,
        author: b.author,
        genre: b.genre,
        description: b.description,
        conditionNote: b.conditionNote,
        status: b.status,
        familyId: b.familyId,
        ownerName: b.family?.name ?? '',
      })),
    );
  } catch (err) {
    console.error('Book catalog error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/book/mine — порт BookController::mine: мои книги (+ кто сейчас
// держит), входящие брони на мои книги и мои заимствования.
router.get('/mine', async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [myBooks, incoming, borrowings] = await Promise.all([
      db.book.findMany({
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
      db.bookLoan.findMany({
        where: { book: { familyId }, status: { in: ['requested', 'on_loan'] } },
        orderBy: { requestedAt: 'asc' },
        include: {
          book: { select: { id: true, title: true } },
          borrower: { select: { name: true } },
        },
      }),
      db.bookLoan.findMany({
        where: { borrowerId: familyId },
        orderBy: { requestedAt: 'desc' },
        include: {
          book: {
            select: {
              id: true,
              title: true,
              family: { select: { name: true } },
            },
          },
        },
      }),
    ]);
    res.json({
      books: myBooks.map((b) => ({
        id: b.id,
        title: b.title,
        author: b.author,
        genre: b.genre,
        status: b.status,
        holder: b.loans[0]?.borrower.name ?? null,
      })),
      incoming: incoming.map((l) => ({
        id: l.id,
        status: l.status,
        bookId: l.bookId,
        bookTitle: l.book.title,
        borrowerName: l.borrower.name,
        dueDate: l.dueDate ? l.dueDate.toISOString().slice(0, 10) : null,
        message: l.message,
        requestedAt: l.requestedAt.toISOString(),
      })),
      borrowings: borrowings.map((l) => ({
        id: l.id,
        status: l.status,
        bookId: l.bookId,
        bookTitle: l.book.title,
        ownerName: l.book.family?.name ?? null,
        dueDate: l.dueDate ? l.dueDate.toISOString().slice(0, 10) : null,
      })),
    });
  } catch (err) {
    console.error('Book mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/book/:id — порт BookController::show: карточка книги, активная
// бронь, права текущей семьи и история броней (только владельцу).
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const book = await db.book.findUnique({
      where: { id },
      include: { family: { select: { id: true, name: true } } },
    });
    if (!book) return res.status(404).json({ error: 'Книга не найдена' });

    const [active, historyRows] = await Promise.all([
      db.bookLoan.findFirst({
        where: { bookId: id, status: 'on_loan' },
        include: { borrower: { select: { name: true } } },
      }),
      db.bookLoan.findMany({
        where: { bookId: id },
        orderBy: { requestedAt: 'desc' },
        include: { borrower: { select: { name: true } } },
      }),
    ]);

    const isOwner = book.familyId === familyId;
    const canRequest = !isOwner && book.status === 'available' && !active;
    res.json({
      book: {
        id: book.id,
        title: book.title,
        author: book.author,
        genre: book.genre,
        description: book.description,
        conditionNote: book.conditionNote,
        status: book.status,
        familyId: book.familyId,
      },
      ownerName: book.family?.name ?? '',
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
    console.error('Book show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

/**
 * Поля книги из тела запроса — порт BookController::validate: title 2–250,
 * genre ≤80, статус из формы только «свободна»/«на руках» (по умолчанию свободна).
 */
function bookFields(req: AuthRequest): {
  data: {
    title: string;
    author: string;
    genre: string;
    description: string | null;
    conditionNote: string | null;
    status: 'available' | 'on_loan';
  } | null;
  errors: Record<string, string>;
} {
  const title = String(req.body?.title ?? '').trim();
  const author = String(req.body?.author ?? '').trim().slice(0, 200);
  const genre = String(req.body?.genre ?? '').trim().slice(0, 80);
  const desc = String(req.body?.description ?? '').trim();
  const cond = String(req.body?.conditionNote ?? req.body?.condition_note ?? '')
    .trim()
    .slice(0, 200);
  const status = (req.body?.status ?? '') === 'on_loan' ? ('on_loan' as const) : ('available' as const);
  const errors: Record<string, string> = {};
  if (title.length < 2 || title.length > 250) errors.title = 'Название: 2–250 символов.';
  if (genre.length > 80) errors.genre = 'Жанр до 80 символов.';
  if (Object.keys(errors).length > 0) return { data: null, errors };
  return {
    data: {
      title,
      author,
      genre,
      description: desc !== '' ? desc : null,
      conditionNote: cond !== '' ? cond : null,
      status,
    },
    errors,
  };
}

// POST /api/book/create — порт BookController::create (без фото и анонса:
// загрузки и CatalogAnnounce в v2 ещё нет).
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { data, errors } = bookFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const book = await db.book.create({
      data: {
        familyId: req.user!.familyId,
        title: data.title,
        author: data.author,
        genre: data.genre,
        description: data.description,
        conditionNote: data.conditionNote,
        status: data.status,
      },
    });
    res.json({ ok: true, id: book.id, status: book.status });
  } catch (err) {
    console.error('Book create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/book/update/:id — правка своей книги (белый список полей:
// req.body целиком в update, как раньше, позволял подменить familyId/status).
router.post('/update/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const book = await db.book.findUnique({ where: { id } });
    if (!book || book.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const { data, errors } = bookFields(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const updated = await db.book.update({
      where: { id },
      data: {
        title: data.title,
        author: data.author,
        genre: data.genre,
        description: data.description,
        conditionNote: data.conditionNote,
        status: data.status,
        updatedAt: new Date(),
      },
    });
    res.json({ ok: true, id: updated.id, status: updated.status });
  } catch (err) {
    console.error('Book update error:', err);
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
