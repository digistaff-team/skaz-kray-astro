import { Request, Response, NextFunction } from 'express';
import { verifyToken, AuthPayload } from '../lib/auth';

export interface AuthRequest extends Request {
  user?: AuthPayload;
}

export function requireAuth(req: AuthRequest, res: Response, next: NextFunction) {
  const token = req.cookies?.token || req.headers.authorization?.replace('Bearer ', '');

  if (!token) {
    return res.status(401).json({ error: 'Не авторизован' });
  }

  const payload = verifyToken(token);
  if (!payload) {
    return res.status(401).json({ error: 'Токен недействителен' });
  }

  req.user = payload;
  next();
}

export function requireAdmin(req: AuthRequest, res: Response, next: NextFunction) {
  if (req.user?.role !== 'admin') {
    return res.status(403).json({ error: 'Доступ запрещён' });
  }
  next();
}

export function requireEditor(req: AuthRequest, res: Response, next: NextFunction) {
  if (!['editor', 'admin'].includes(req.user?.role || '')) {
    return res.status(403).json({ error: 'Доступ запрещён' });
  }
  next();
}
