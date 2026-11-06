import { Request, Response, NextFunction } from 'express';

/**
 * Страховка от отдачи хешей паролей наружу.
 *
 * Контроллеры берут `family`/`councilMember` целиком (без `select`), и Prisma
 * возвращает все колонки, включая password_hash. Требовать `select`/`omit` в
 * каждом из ~90 обработчиков — способ однажды его забыть, поэтому хеш вырезается
 * здесь, на границе HTTP: внутренний код (`verifyPassword`) по-прежнему видит
 * поле в объекте, а в JSON оно не попадает.
 *
 * Поле удаляется рекурсивно по имени, а не по типу: так закрыты и будущие
 * модели с тем же колонкой `password_hash`.
 */
const SECRET_FIELDS = new Set(['passwordHash', 'password_hash', 'tokenHash', 'token_hash']);

function scrub(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(scrub);
  if (value === null || typeof value !== 'object') return value;
  // Date и прочие не-plain-объекты отдаём как есть — их сериализует JSON.stringify.
  if (!(value.constructor === Object || value.constructor === undefined)) return value;

  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
    if (SECRET_FIELDS.has(k)) continue;
    out[k] = scrub(v);
  }
  return out;
}

export function redactSecrets(req: Request, res: Response, next: NextFunction) {
  const original = res.json.bind(res);
  res.json = ((body: unknown) => original(scrub(body))) as Response['json'];
  next();
}
