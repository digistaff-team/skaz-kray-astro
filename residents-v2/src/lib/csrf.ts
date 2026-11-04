import crypto from 'crypto';
import { Request, Response, NextFunction } from 'express';

const SESSION_SECRET = process.env.SESSION_SECRET || 'change-me';

export function csrfToken(): string {
  return crypto.randomBytes(32).toString('hex');
}

export function signCsrf(token: string): string {
  return crypto.createHmac('sha256', SESSION_SECRET).update(token).digest('hex');
}

export function verifyCsrf(token: string, sig: string): boolean {
  const expected = signCsrf(token);
  if (token.length !== expected.length) return false;
  return crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}

// Middleware: проверяет _csrf в теле POST-запроса.
// Для API-роутов с JWT в cookie/hedare можно использовать двойную отправку (double-submit).
export function csrfGuard(req: Request, res: Response, next: NextFunction) {
  if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();

  const token = (req.body?._csrf as string) || (req.headers['x-csrf-token'] as string);
  const cookieToken = req.cookies?.csrf;

  if (!token || !cookieToken || token !== cookieToken) {
    return res.status(403).json({ error: 'CSRF-проверка не пройдена' });
  }
  next();
}
