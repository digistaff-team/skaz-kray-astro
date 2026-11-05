import jwt, { SignOptions } from 'jsonwebtoken';

export interface AuthPayload {
  familyId: number;
  email: string;
  role: string;
}

// Секреты читаются внутри функций, а не на уровне модуля: при холодном старте
// серверлесс-функции порядок инициализации окружения не гарантирован.
function secret(): string {
  const s = process.env.JWT_SECRET;
  if (!s) throw new Error('JWT_SECRET не задан');
  return s;
}

export function signToken(payload: AuthPayload): string {
  const opts: SignOptions = { expiresIn: (process.env.JWT_EXPIRES_IN || '7d') as SignOptions['expiresIn'] };
  return jwt.sign(payload, secret(), opts);
}

export function verifyToken(token: string): AuthPayload | null {
  try {
    return jwt.verify(token, secret()) as AuthPayload;
  } catch {
    return null;
  }
}
