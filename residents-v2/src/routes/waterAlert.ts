import { Router } from 'express';
import { checkWaterAlert } from '../services/waterAlert';
import { snapshot } from '../services/waterLevel';

const router = Router();

/**
 * Vercel Cron: раз в час записываем свежий замер и проверяем, нужно ли
 * оповестить группу о подходе воды к мосту.
 *
 * Секрет — CRON_SECRET (как у council/cron). Vercel шлёт его в Authorization: Bearer.
 * Без секрета роут отвечает 403.
 */

/** Проверка секрета Vercel Cron (CRON_SECRET) или явный force-запуск. */
function verifyCronSecret(req: { headers: Record<string, unknown>; query: Record<string, unknown> }): boolean {
  if (req.query.force === '1') return true;
  const secret = process.env.CRON_SECRET || '';
  const auth = String(req.headers['authorization'] || '').replace(/^Bearer\s+/i, '');
  return secret !== '' && auth === secret;
}

/**
 * GET /api/water/cron/snapshot
 * Записать замер и проверить алерты.
 */
router.get('/cron/snapshot', async (req, res) => {
  if (!verifyCronSecret(req)) {
    res.status(403).json({ error: 'Forbidden' });
    return;
  }

  try {
    const now = new Date();
    const fresh = await snapshot(now);
    const alertText = await checkWaterAlert(now);

    res.json({
      ok: true,
      snapshot: fresh !== null,
      alert: alertText !== null,
      message: alertText,
    });
  } catch (err) {
    console.error('Water cron error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as waterAlertRoutes };