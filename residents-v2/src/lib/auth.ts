import jwt, { SignOptions } from 'jsonwebtoken';

export interface AuthPayload {
  familyId: number;
  email: string;
  role: string;
}

/**
 * Токен члена Попечительского совета. Полностью раздель с токеном жителя
 * (легаси: CouncilAuth использует собственные ключи сессии council_* —
 * один email может быть и семьёй, и членом совета, входы не мешают друг другу).
 * kind в payload + отдельная cookie council_token закрывают взаимное
 * использование: requireAuth отклоняет советские токены и наоборот.
 */
export interface CouncilPayload {
  kind: 'council';
  memberId: number;
  email: string;
  role: string; // 'member' | 'admin'
}

export type TokenPayload = AuthPayload | CouncilPayload;

/** Токен совета — отличается от токена жителя (см. CouncilPayload). */
export function isCouncilPayload(p: TokenPayload | null): p is CouncilPayload {
  return p !== null && 'kind' in p && p.kind === 'council';
}

// Секреты читаются внутри функций, а не на уровне модуля: при холодном старте
// серверлесс-функции порядок инициализации окружения не гарантирован.
function secret(): string {
  const s = process.env.JWT_SECRET;
  if (!s) throw new Error('JWT_SECRET не задан');
  return s;
}

export function signToken(payload: TokenPayload): string {
  const opts: SignOptions = { expiresIn: (process.env.JWT_EXPIRES_IN || '7d') as SignOptions['expiresIn'] };
  return jwt.sign(payload, secret(), opts);
}

export function verifyToken(token: string): TokenPayload | null {
  try {
    return jwt.verify(token, secret()) as TokenPayload;
  } catch {
    return null;
  }
}
