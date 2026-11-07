import { Response, NextFunction } from 'express';
import { db } from '../lib/db';
import { AuthRequest } from './auth';

/**
 * Гард привязанного поместья — порт RequiresHousehold: разделы, завязанные на
 * поместье (Книги, Иннструменты и др.), доступны только вошедшему жителю с
 * привязанным поместьем. Без поместья — 403 {reason:'no_household'}: клиент
 * уводит на выбор поместья (в легаси это был редирект со flash).
 */
export async function requireHousehold(req: AuthRequest, res: Response, next: NextFunction) {
  try {
    const owner = await db.householdOwner.findUnique({
      where: { familyId: req.user!.familyId },
      select: { id: true },
    });
    if (!owner) {
      return res.status(403).json({
        reason: 'no_household',
        error: 'Сначала выберите ваше поместье — после этого откроется раздел.',
      });
    }
    next();
  } catch (err) {
    console.error('Household guard error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
}
