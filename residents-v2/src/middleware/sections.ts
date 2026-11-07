import { Response, NextFunction } from 'express';
import { db } from '../lib/db';
import { AuthRequest } from './auth';

/**
 * Гард раздела — порт RequiresSection: выключенный админом раздел закрыт и по
 * прямой ссылке (403), неизвестный ключ считается включённым (паритет с
 * легаси isEnabled). Ставится ПОСЛЕ requireAuth: список разделов не ПДн, но
 * смысл легаси — «закрыто админом», поэтому и для вошедшего отвечаем 403.
 */
export function requireSection(sectionKey: string) {
  return async (req: AuthRequest, res: Response, next: NextFunction) => {
    try {
      const row = await db.appSection.findUnique({ where: { sectionKey } });
      if (row && row.enabled === false) {
        return res.status(403).json({ error: 'Раздел сейчас отключён в поселении' });
      }
      next();
    } catch (err) {
      console.error('Section check error:', err);
      res.status(500).json({ error: 'Ошибка' });
    }
  };
}
