// dotenv/config обязан быть ПЕРВЫМ импортом: lib/auth.ts читает process.env.JWT_SECRET
// на уровне модуля, а в собранном CommonJS все require идут строго в этом порядке.
// Просто вызвать dotenv.config() между импортами нельзя — сборка поднимет её наверх.
import 'dotenv/config';
import express from 'express';
import helmet from 'helmet';
import cors from 'cors';
import cookieParser from 'cookie-parser';

import { authRoutes } from './routes/auth';
import { cabinetRoutes } from './routes/cabinet';
import { diaryRoutes } from './routes/diary';
import { productRoutes } from './routes/product';
import { profileRoutes } from './routes/profile';
import { moderationRoutes } from './routes/moderation';
import { toolRoutes } from './routes/tool';
import { bookRoutes } from './routes/book';
import { tripRoutes } from './routes/trip';
import { deliveryRoutes } from './routes/delivery';
import { purchaseRoutes } from './routes/purchase';
import { tgRoutes } from './routes/tg';
import { maxRoutes } from './routes/max';
import { councilRoutes } from './routes/council';
import { publicRoutes } from './routes/public';
import { healthRoutes } from './routes/health';

const app = express();

// Безопасность
app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      styleSrc: ["'self'", "'unsafe-inline'", "https://fonts.googleapis.com"],
      fontSrc: ["'self'", "https://fonts.gstatic.com"],
      imgSrc: ["'self'", "data:", "https://"],
    },
  },
}));
app.use(cors({ origin: process.env.BASE_URL }));
app.use(express.json({ limit: '200mb' }));
app.use(express.urlencoded({ extended: true, limit: '200mb' }));
app.use(cookieParser());

// Статика (только при локальном запуске — на Vercel её нет)
if (process.env.NODE_ENV !== 'production') {
  app.use('/poselenie/uploads', express.static('./uploads'));
}

// Роуты
app.use('/api/auth', authRoutes);
app.use('/api/cabinet', cabinetRoutes);
app.use('/api/diary', diaryRoutes);
app.use('/api/products', productRoutes);
app.use('/api/profile', profileRoutes);
app.use('/api/moderation', moderationRoutes);
app.use('/api/tools', toolRoutes);
app.use('/api/books', bookRoutes);
app.use('/api/trips', tripRoutes);
app.use('/api/deliveries', deliveryRoutes);
app.use('/api/purchases', purchaseRoutes);
app.use('/api/tg', tgRoutes);
app.use('/api/max', maxRoutes);
app.use('/api/council', councilRoutes);
app.use('/api/public', publicRoutes);
app.use('/api/health', healthRoutes);

// Health check
app.get('/api/health', (_req: any, res: any) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

// 404
app.use((_req: any, res: any) => {
  res.status(404).json({ error: 'Not found' });
});

// Error handler
app.use((err: Error, _req: any, res: any, _next: any) => {
  console.error('Unhandled error:', err);
  res.status(500).json({ error: 'Internal server error' });
});

// Локальный запуск (для dev-тестов на abconsult)
if (process.env.NODE_ENV !== 'production') {
  const PORT = process.env.PORT || 3001;
  app.listen(PORT, () => {
    console.log(`Residents V2 running on port ${PORT}`);
  });
}

// Vercel сам принимает (req, res) в нативном рантайме — serverless-http здесь
// не нужен: он ждёт событие AWS Lambda и зависает до таймаута (504/Task timed out).
// Express-приложение само является функцией (req, res) и валидно как default export.
export default app;
