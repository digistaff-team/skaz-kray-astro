import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';
import { householdTitle } from '../lib/household-name';

const router = Router();

// Ключ/номер поляны и номер участка — порт ResidentsRepository.
function gladeKey(g: string): string {
  return g.trim().replace(/\s+/g, ' ').toLowerCase();
}
function gladeNum(g: string): number {
  const m = /^\((\d+)\)/.exec(g.trim());
  return m ? parseInt(m[1], 10) : 999;
}
function plotNum(p: string): number {
  const m = /\d+/.exec(p.trim());
  return m ? parseInt(m[0], 10) : 9999;
}

/** Эвристика из _glade_body.php: точное слово = 2, начало слова (≥3 симв.) = 1. */
function nameScore(residentFullName: string, tgName: string): number {
  const res = residentFullName.toLowerCase().trim().split(/\s+/).filter(Boolean);
  const tg = tgName.toLowerCase().trim().split(/\s+/).filter(Boolean);
  let sum = 0;
  for (const t of tg) {
    let best = 0;
    for (const r of res) {
      if (r === t) {
        best = 2;
        break;
      }
      if (r.length >= 3 && t.length >= 3 && (r.startsWith(t) || t.startsWith(r))) best = 1;
    }
    sum += best;
  }
  return sum;
}

interface Person {
  id: number;
  fullName: string;
  phone: string;
  vk: string;
  skills: string;
  communityRole: string;
  hometown: string;
  residence: string;
  tgUsername: string | null;
}
interface Row {
  id: number;
  glade: string;
  plot: string;
  estateName: string;
  claimed: boolean;
  title: string;
  people: Person[];
  cars: Array<{ title: string; plate: string; note: string }>;
  pets: Array<{ name: string; kind: string; note: string }>;
}

// GET /api/residents/directory — справочник «Наши соседи» (порт
// ResidentsRepository::grouped/gladeGroups + stats). ПДн: жителей, авто и
// питомцев отдаём ТОЛЬКО у привязанных поместий — у остальных они пустые.
router.get('/directory', requireAuth, requireSection('sosedi'), async (req: AuthRequest, res) => {
  try {
    const q = String(req.query.q ?? '').trim();

    const [households, people, cars, pets, householdsCount, peopleCount, tgFamilies] =
      await Promise.all([
        db.household.findMany({
          orderBy: [{ sort: 'asc' }, { id: 'asc' }],
          include: { family: { select: { status: true } } },
        }),
        db.resident.findMany({ orderBy: [{ householdId: 'asc' }, { sort: 'asc' }, { id: 'asc' }] }),
        db.householdCar.findMany({ orderBy: [{ sort: 'asc' }, { id: 'asc' }] }),
        db.householdPet.findMany({ orderBy: [{ sort: 'asc' }, { id: 'asc' }] }),
        db.household.count(),
        db.resident.count(),
        db.family.findMany({
          where: { status: 'active', telegramId: { not: null }, telegramUsername: { not: '' } },
          select: {
            id: true,
            telegramUsername: true,
            name: true,
            households: { select: { id: true } },
            householdOwners: { select: { householdId: true } },
          },
        }),
      ]);

    // Подключённые аккаунты (только публичный @username) — основной владелец
    // и совладельцы, dedup по username (порт accountsByHousehold).
    const accountsBy = new Map<number, Array<{ tgUsername: string; tgName: string }>>();
    const addAccount = (hid: number, username: string | null, name: string) => {
      if (!username) return;
      const list = accountsBy.get(hid) || [];
      if (!list.some((a) => a.tgUsername === username)) {
        list.push({ tgUsername: username, tgName: name });
      }
      accountsBy.set(hid, list);
    };
    for (const f of tgFamilies) {
      for (const h of f.households) addAccount(h.id, f.telegramUsername, f.name);
      for (const o of f.householdOwners) addAccount(o.householdId, f.telegramUsername, f.name);
    }

    const group = <T extends { householdId: number }>(rows: T[]): Map<number, T[]> => {
      const out = new Map<number, T[]>();
      for (const r of rows) {
        const list = out.get(r.householdId) || [];
        list.push(r);
        out.set(r.householdId, list);
      }
      return out;
    };
    const peopleBy = group(people);
    const carsBy = group(cars);
    const petsBy = group(pets);

    const rows: Row[] = households.map((h) => {
      const claimed = h.familyId !== null && h.family?.status === 'active';
      const allPeople = peopleBy.get(h.id) || [];
      const personRows = claimed ? allPeople : [];
      // Имя главы (первый житель по sort) — для заголовка, независимо от привязки.
      const headName = allPeople[0]?.fullName ?? null;
      const accounts = accountsBy.get(h.id) || [];
      const people: Person[] = personRows.map((p) => {
        let best = 0;
        let tg: string | null = null;
        for (const a of accounts) {
          const score = nameScore(p.fullName, a.tgName);
          if (score > best) {
            best = score;
            tg = a.tgUsername;
          }
        }
        return {
          id: p.id,
          fullName: p.fullName,
          phone: p.phone,
          vk: p.vk,
          skills: p.skills ?? '',
          communityRole: p.communityRole ?? '',
          hometown: p.hometown,
          residence: p.residence,
          tgUsername: best > 0 ? tg : null,
        };
      });
      return {
        id: h.id,
        glade: h.glade,
        plot: h.plot,
        estateName: h.estateName,
        claimed,
        title: householdTitle(h.estateName, headName),
        people,
        cars: claimed
          ? (carsBy.get(h.id) || []).map((c) => ({ title: c.title, plate: c.plate, note: c.note }))
          : [],
        pets: claimed
          ? (petsBy.get(h.id) || []).map((p) => ({ name: p.name, kind: p.kind, note: p.note }))
          : [],
      };
    });

    // Поиск — порт matches(): поместье остаётся, если совпадение есть у него
    // самого (поляна/название/заголовок) или любого из его жителей.
    const needle = q.toLowerCase();
    const matched =
      needle === ''
        ? rows
        : rows.filter((r) => {
            const hay = [r.glade, r.estateName, r.title];
            for (const p of r.people) {
              hay.push(p.fullName, p.skills, p.communityRole, p.hometown);
            }
            return hay.some((f) => f && f.toLowerCase().includes(needle));
          });

    // Раскладка по полянам (порт gladeGroups): ключ нормализован, порядок —
    // по номеру поляны, внутри — по номеру участка.
    const byGlade = new Map<
      string,
      { key: string; name: string; num: number; households: Row[] }
    >();
    for (const r of matched) {
      const key = gladeKey(r.glade);
      let g = byGlade.get(key);
      if (!g) {
        g = { key, name: r.glade, num: gladeNum(r.glade), households: [] };
        byGlade.set(key, g);
      }
      g.households.push(r);
    }
    const glades = [...byGlade.values()];
    glades.sort((a, b) => a.num - b.num);
    for (const g of glades) {
      g.households.sort((a, b) => plotNum(a.plot) - plotNum(b.plot));
    }

    res.json({
      q,
      stats: { households: householdsCount, people: peopleCount },
      glades,
    });
  } catch (err) {
    console.error('Directory error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// Модерация аккаунтов (бывшие /sosedi/approve|reject) — оставлены как были.
router.post('/approve', requireAuth, async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'active', approvedAt: new Date() },
    });
    res.json({ id: family.id, status: family.status });
  } catch (err) {
    console.error('Approve error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

router.post('/reject', requireAuth, async (req: AuthRequest, res) => {
  try {
    const family = await db.family.update({
      where: { id: Number(req.body.familyId) },
      data: { status: 'blocked' },
    });
    res.json({ id: family.id, status: family.status });
  } catch (err) {
    console.error('Reject error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as residentsRoutes };
