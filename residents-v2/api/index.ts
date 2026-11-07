// Точка входа Vercel для всех /api/* (см. vercel.json rewrites + request.path
// transform): rewrite указывает сюда, transform сохраняет оригинальный URL
// в req.url, поэтому Express видит полные маршруты (/api/tg/login и т.д.).
// Vercel забирает только default-экспорт.
export { default } from '../src/server';
