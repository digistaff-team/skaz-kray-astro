import { Request, Response, NextFunction } from 'express';
import { verifyToken, AuthPayload, CouncilPayload, isCouncilPayload } from '../lib/auth';

export interface AuthRequest extends Request {
  user?: AuthPayload;
  /** Заполнен гвардами совета (requireCouncil); req.user при этом не используется. */
  council?: CouncilPayload;
}

/**
 * Вход жителей. Советский токен (kind:'council') здесь отклоняется: иначе
 * токен члена совета проходил бы как семья с familyId = memberId (легаси
 * держит сессии полностью раздельно — council_* против family_*).
 */
export function requireAuth(req: AuthRequest, res: Response, next: NextFunction) {
  const token = req.cookies?.token || req.headers.authorization?.replace('Bearer ', '');

  if (!token) {
    return res.status(401).json({ error: 'Не авторизован' });
  }

  const payload = verifyToken(token);
  if (!payload || isCouncilPayload(payload)) {
    return res.status(401).json({ error: 'Токен недействителен' });
  }

  req.user = payload;
  next();
}

/**
 * Вход членов Попечительского совета: своя cookie (council_token) и свой
 * payload (kind:'council'). Токен жителя здесь не принимается — паритет
 * с раздельными ключами сессии CouncilAuth в легаси.
 */
export function requireCouncil(req: AuthRequest, res: Response, next: NextFunction) {
  const token = req.cookies?.council_token || req.headers.authorization?.replace('Bearer ', '');

  if (!token) {
    return res.status(401).json({ error: 'Не авторизован' });
  }

  const payload = verifyToken(token);
  if (!payload || !isCouncilPayload(payload)) {
    return res.status(401).json({ error: 'Токен недействителен' });
  }

  req.council = payload;
  next();
}

/** Только администратор совета (легаси CouncilAuth::requireAdmin). */
export function requireCouncilAdmin(req: AuthRequest, res: Response, next: NextFunction) {
  requireCouncil(req, res, (err?: unknown) => {
    if (err) return next(err);
    if (req.council?.role !== 'admin') {
      return res.status(403).json({ error: 'Доступ только для администратора совета.' });
    }
    next();
  });
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
