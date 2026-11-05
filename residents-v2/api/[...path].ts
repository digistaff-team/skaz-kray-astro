// Единая серверлесс-функция Vercel: ловит все /api/* и отдаёт их Express-приложению.
// Имя [...path] сохраняет исходный URL — serverless-http читает event.path,
// поэтому Express видит полные маршруты (/api/auth/login), а не укороченные.
import 'dotenv/config';

export { handler } from '../src/server';
