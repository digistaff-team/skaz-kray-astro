import { PrismaClient } from '@prisma/client';

// Prisma отдаёт BigInt-колонки (telegram_id, max_user_id, chat_id, group_id)
// значениями bigint, а res.json() падает на них с «Do not know how to serialize
// a BigInt». Числа Telegram/MAX/VK заведомо внутри 2^53, поэтому отдаём number.
// TS не знает про BigInt.prototype.toJSON — обходим через any.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const anyProto = BigInt.prototype as any;
anyProto.toJSON = function () {
  return this <= BigInt(Number.MAX_SAFE_INTEGER) ? Number(this) : this.toString();
};

const globalForPrisma = globalThis as unknown as { prisma?: PrismaClient };

export const db = globalForPrisma.prisma ?? new PrismaClient();

if (process.env.NODE_ENV !== 'production') globalForPrisma.prisma = db;
