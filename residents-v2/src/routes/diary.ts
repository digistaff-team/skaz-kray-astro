import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/dnevnik/novaya
router.get('/create', requireAuth, (_req: AuthRequest, res) => {
  res.json({ form: 'diary-create' });
});

// POST /poselenie/dnevnik/novaya
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { title, body, isPublic } = req.body;
    const familyId = req.user!.familyId;

    const family = await db.family.findUnique({
      where: { id: familyId },
      include: { householdOwners: { include: { household: true } } },
    });

    if (!family?.householdOwners.length) {
      return res.status(403).json({ error: 'Сначала привяжите поместье' });
    }

    const entry = await db.diaryEntry.create({
      data: {
        familyId,
        title,
        body,
        visibility: isPublic ? 'public' : 'residents',
      },
    });

    res.json({ id: entry.id, message: 'Запись создана' });
  } catch (err) {
    console.error('Diary create error:', err);
    res.status(500).json({ error: 'Ошибка создания' });
  }
});

// GET /poselenie/dnevniki (внутренняя лента)
router.get('/feed', requireAuth, async (req: AuthRequest, res) => {
  try {
    const entries = await db.diaryEntry.findMany({
      where: { status: 'PUBLISHED' },
      include: { family: { include: { householdOwners: { include: { household: true } } } } },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    res.json(entries);
  } catch (err) {
    console.error('Diary feed error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/dnevnik/{id}/redaktiroват
router.get('/edit/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const entry = await db.diaryEntry.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!entry || entry.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    res.json(entry);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dnevnik/{id}/redaktirovat
router.post('/update/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { title, body } = req.body;
    const entry = await db.diaryEntry.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!entry || entry.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.diaryEntry.update({
      where: { id: entry.id },
      data: { title, body },
    });

    res.json(updated);
  } catch (err) {
    console.error('Diary update error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/dnevnik/{id}/удалить
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const entry = await db.diaryEntry.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!entry || entry.familyId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.diaryEntry.delete({ where: { id: entry.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as diaryRoutes };
