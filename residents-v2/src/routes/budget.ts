import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireSection } from '../middleware/sections';
import { buildLedgerReport } from '../services/ledgerReport';

const router = Router();

/**
 * Бюджет Общего дома — read-only отчёт для всех авторизованных жителей.
 * Порт BudgetController. Данные собирает LedgerReport — тот же источник,
 * что у страницы совета, поэтому цифры совпадают.
 */

router.get('/', requireAuth, requireSection('byudzhet'), async (req: AuthRequest, res) => {
  try {
    const ym = typeof req.query.mesyac === 'string' ? req.query.mesyac : null;
    const report = await buildLedgerReport(ym);
    res.json(report);
  } catch (err) {
    console.error('Budget error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as budgetRoutes };
