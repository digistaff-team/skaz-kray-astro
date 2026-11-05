import { Router } from 'express';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/dnevniki (публичная лента дневников)
router.get('/diary', async (_req, res) => {
  try {
    const entries = await db.diaryEntry.findMany({
      where: { visibility: 'public', status: 'published' },
      include: {
        family: { include: { householdOwners: { include: { household: true } } } },
      },
      orderBy: { publishedAt: 'desc' },
      take: 50,
    });
    res.json(entries);
  } catch (err) {
    console.error('Public diary error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/yarmarka (публичная ярмарка)
router.get('/products', async (_req, res) => {
  try {
    const products = await db.product.findMany({
      where: { visibility: 'public', status: 'published' },
      include: {
        family: { include: { householdOwners: { include: { household: true } } } },
      },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    res.json(products);
  } catch (err) {
    console.error('Public products error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/sosedi (публичный справочник — если не приватный)
router.get('/directory', async (_req, res) => {
  try {
    const sections = await db.appSection.findMany();
    const directoryEnabled = sections.find(s => s.sectionKey === 'directory')?.enabled !== false;

    if (!directoryEnabled) {
      return res.status(403).json({ error: 'Раздел скрыт' });
    }

    const owners = await db.householdOwner.findMany({
      include: { household: true },
      where: { family: { status: 'active' } },
    });
    res.json(owners);
  } catch (err) {
    console.error('Public directory error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as publicRoutes };
