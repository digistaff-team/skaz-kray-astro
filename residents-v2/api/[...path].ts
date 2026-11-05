// Единая серверлесс-функция Vercel: ловит все /api/* и отдаёт их Express-приложению.
// Имя [...path] сохраняет исходный URL — serverless-http читает event.path,
// поэтому Express видит полные маршруты (/api/auth/login), а не укороченные.
// Vercel забирает только default-экспорт.
export { default } from '../src/server';
