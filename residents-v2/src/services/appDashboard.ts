import { db } from '../lib/db';
import { Decimal } from '@prisma/client/runtime/library';

/**
 * Данные мобильного лаунчера /poselenie/app: статус дневника семьи, счётчик
 * закупок и баннер дня рождения. Только чтение.
 * Порт residents/src/Service/AppDashboard.php.
 */

function toNum(d: Decimal | number | null | undefined): number {
  if (d === null || d === undefined) return 0;
  return typeof d === 'number' ? d : parseFloat(String(d));
}

/** «2026-08-29 10:00:00» → «29 августа 2026» */
function ruDate(s: string): string {
  if (!s) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  if (!m) return s;
  const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
  return `${Number(m[3])} ${months[Number(m[1]) - 1]} ${m[0].split('-')[0]}`;
}

export interface DashboardData {
  diary: {
    count: number;
    latestTitle: string | null;
    latestStatus: string | null;
  };
  otherDiaries: Array<{ id: number; title: string; family: string }>;
  counts: { purchases: number };
  birthdays: Array<{
    fullName: string;
    household: { estateName: string; glade: string; plot: string };
  }>;
}

export async function buildDashboard(familyId: number): Promise<DashboardData> {
  // 1. Дневник семьи
  const entries = await db.diaryEntry.findMany({
    where: { familyId },
    orderBy: { id: 'desc' },
    select: { title: true, status: true },
    take: 1,
  });
  const latest = entries[0] ?? null;

  // 2. Две свежие опубликованные записи ДРУГИХ поместий
  const others = await db.diaryEntry.findMany({
    where: {
      status: 'published',
      visibility: { not: 'private' },
      familyId: { not: familyId },
    },
    orderBy: { publishedAt: 'desc' },
    take: 2,
    select: {
      id: true,
      title: true,
      family: { select: { name: true } },
    },
  });

  // 3. Счётчик закупок (collecting)
  let purchasesCount = 0;
  try {
    purchasesCount = await db.purchase.count({
      where: { organizerId: familyId, status: 'collecting' },
    });
  } catch (e) {
    console.error('AppDashboard purchases count error:', e);
  }

  // 4. Дни рождения сегодня
  const today = new Date();
  const todayMonth = today.getMonth() + 1;
  const todayDay = today.getDate();

  const residents = await db.resident.findMany({
    where: {
      household: { family: { status: 'active' } },
      birthDate: { not: null },
    },
    select: {
      fullName: true,
      birthDate: true,
      household: {
        select: {
          estateName: true,
          glade: true,
          plot: true,
        },
      },
    },
  });

  const birthdays = residents
    .filter((r) => {
      if (!r.birthDate) return false;
      const bd = new Date(r.birthDate);
      return bd.getMonth() + 1 === todayMonth && bd.getDate() === todayDay;
    })
    .map((r) => ({
      fullName: r.fullName,
      household: {
        estateName: r.household.estateName,
        glade: r.household.glade,
        plot: r.household.plot,
      },
    }));

  return {
    diary: {
      count: await db.diaryEntry.count({ where: { familyId } }),
      latestTitle: latest?.title ?? null,
      latestStatus: latest?.status ?? null,
    },
    otherDiaries: others.map((e) => ({
      id: e.id,
      title: e.title,
      family: e.family?.name ?? '',
    })),
    counts: { purchases: purchasesCount },
    birthdays,
  };
}
