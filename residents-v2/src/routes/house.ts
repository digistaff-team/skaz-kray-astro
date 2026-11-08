import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';
import { protocols } from '../lib/councilData';

const router = Router();

/**
 * «Общий дом» — раздел-хаб для жителей (порт CommonHouseController).
 * Своих данных у раздела нет: бронирование живёт в мини-приложении
 * @SkazTerem_bot (ссылка — TEREM_BOOKING_LINK), отчёт — страница «Бюджет
 * Общего дома» (/poselenie/byudzhet), протоколы — тот же источник, что в
 * /sovet (councilData::protocols).
 */

// GET /api/house — ссылка на бронирование, флаг доступности бюджета, протоколы.
router.get('/', requireAuth, requireSection('obshchiy-dom'), async (_req: AuthRequest, res) => {
  try {
    // Отсутствие строки = включён (паритет с Sections::isEnabled).
    const row = await db.appSection.findUnique({ where: { sectionKey: 'byudzhet' } });
    res.json({
      bookingLink: process.env.TEREM_BOOKING_LINK || 'https://t.me/SkazTerem_bot/booking',
      budgetOpen: row ? row.enabled : true,
      protocols: protocols(),
    });
  } catch (err) {
    console.error('Common house error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as houseRoutes };
