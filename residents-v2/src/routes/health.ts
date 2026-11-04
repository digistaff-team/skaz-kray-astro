import { Router } from 'express';

const router = Router();

// Простой health-check endpoint
router.get('/', (_req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

export { router as healthRoutes };
