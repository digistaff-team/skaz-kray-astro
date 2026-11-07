import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();
const PER_PAGE = 10;

type Visibility = 'private' | 'residents' | 'public';

/** private (только я) | residents (соседи) | public (все на сайте); дефолт — residents. */
function pickVisibility(v: unknown): Visibility {
  return v === 'private' || v === 'public' || v === 'residents' ? v : 'residents';
}

/**
 * Лента: ВСЕ опубликованные записи, кроме «Только я» — порт условий
 * listPublished/countPublished/findPublishedById легаси (private видит только автор).
 */
function feedWhere() {
  return { status: 'published', visibility: { not: 'private' } } as const;
}

function viewEntry(e: {
  id: number;
  title: string;
  body: string;
  isPublic: boolean;
  visibility: string;
  status: string;
  rejectReason: string | null;
  familyId: number;
  createdAt: Date;
  publishedAt: Date | null;
  family?: { name: string } | null;
}) {
  const text = e.body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  return {
    id: e.id,
    title: e.title,
    // excerpt как mb_strimwidth(strip_tags(body), 0, 300, '…') в легаси.
    excerpt: text.length > 300 ? text.slice(0, 300) + '…' : text,
    visibility: e.visibility,
    status: e.status,
    isPublic: e.isPublic,
    rejectReason: e.rejectReason,
    familyId: e.familyId,
    familyName: e.family?.name ?? null,
    publishedAt: e.publishedAt ? e.publishedAt.toISOString() : null,
    createdAt: e.createdAt.toISOString(),
  };
}

/** title 2–200 + непустой текст — порт DiaryController::validate. */
function validate(req: AuthRequest): {
  data: { title: string; body: string; visibility: Visibility } | null;
  errors: Record<string, string>;
} {
  const title = String(req.body?.title ?? '').trim();
  const body = String(req.body?.body ?? '').trim();
  const visibility = pickVisibility(req.body?.visibility);
  const errors: Record<string, string> = {};
  if (title.length < 2 || title.length > 200) errors.title = 'Заголовок: 2–200 символов.';
  if (!body) errors.body = 'Текст записи не может быть пустым.';
  if (Object.keys(errors).length > 0) return { data: null, errors };
  return { data: { title, body, visibility }, errors };
}

// GET /api/diary/feed?page=N — внутренняя лента (порт DiaryController::feed):
// опубликованные записи + «Мои записи» любой видимости/статуса + счётчик.
router.get('/feed', requireAuth, requireSection('dnevniki'), async (req: AuthRequest, res) => {
  try {
    const page = Math.max(1, Number(req.query.page) || 1);
    const familyId = req.user!.familyId;
    const [total, entries, mine] = await Promise.all([
      db.diaryEntry.count({ where: feedWhere() }),
      db.diaryEntry.findMany({
        where: feedWhere(),
        orderBy: { publishedAt: 'desc' },
        skip: (page - 1) * PER_PAGE,
        take: PER_PAGE,
        include: { family: { select: { name: true } } },
      }),
      db.diaryEntry.findMany({
        where: { familyId },
        orderBy: { updatedAt: 'desc' },
        include: { family: { select: { name: true } } },
      }),
    ]);
    res.json({
      page,
      perPage: PER_PAGE,
      total,
      entries: entries.map(viewEntry),
      mine: mine.map(viewEntry),
    });
  } catch (err) {
    console.error('Diary feed error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/diary/entry/:id — порт feedShow: опубликованная не-приватная ЛИБО
// своя запись любой видимости/статуса (чужие private не раскрываем).
router.get('/entry/:id', requireAuth, requireSection('dnevniki'), async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const entry = await db.diaryEntry.findUnique({
      where: { id },
      include: { family: { select: { name: true } } },
    });
    if (!entry) return res.status(404).json({ error: 'Запись не найдена' });
    const own = entry.familyId === familyId;
    const readable = (entry.status === 'published' && entry.visibility !== 'private') || own;
    if (!readable) return res.status(404).json({ error: 'Запись не найдена' });
    res.json({ ...viewEntry(entry), body: entry.body });
  } catch (err) {
    console.error('Diary entry error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/diary/create — порт DiaryController::create + DiaryRepository::create:
// private/residents публикуются сразу, public уходит редактору (pending).
router.post('/create', requireAuth, requireSection('dnevniki'), async (req: AuthRequest, res) => {
  try {
    const { data, errors } = validate(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const isPublic = data.visibility === 'public';
    const now = new Date();
    const entry = await db.diaryEntry.create({
      data: {
        familyId: req.user!.familyId,
        title: data.title,
        body: data.body,
        visibility: data.visibility,
        isPublic,
        status: isPublic ? 'pending' : 'published',
        publishedAt: isPublic ? null : now,
        updatedAt: now,
      },
    });
    res.json({ ok: true, id: entry.id, status: entry.status });
  } catch (err) {
    console.error('Diary create error:', err);
    res.status(500).json({ error: 'Ошибка создания' });
  }
});

// POST /api/diary/update/:id — порт DiaryRepository::update: смена visibility
// сбрасывает статус (public → pending на проверку, иначе published сразу).
router.post('/update/:id', requireAuth, requireSection('dnevniki'), async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const entry = await db.diaryEntry.findUnique({ where: { id } });
    if (!entry || entry.familyId !== familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }
    const { data, errors } = validate(req);
    if (!data) return res.status(400).json({ ok: false, errors });

    const isPublic = data.visibility === 'public';
    const now = new Date();
    const updated = await db.diaryEntry.update({
      where: { id },
      data: {
        title: data.title,
        body: data.body,
        visibility: data.visibility,
        isPublic,
        status: isPublic ? 'pending' : 'published',
        rejectReason: null,
        publishedAt: isPublic ? null : now,
        updatedAt: now,
      },
    });
    res.json({ ok: true, id: updated.id, status: updated.status });
  } catch (err) {
    console.error('Diary update error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/diary/delete/:id — удаление своей записи (порт delete + ownedOr404).
router.post('/delete/:id', requireAuth, requireSection('dnevniki'), async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const { count } = await db.diaryEntry.deleteMany({ where: { id, familyId } });
    if (count === 0) return res.status(404).json({ error: 'Не найдено' });
    res.json({ ok: true });
  } catch (err) {
    console.error('Diary delete error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as diaryRoutes };
