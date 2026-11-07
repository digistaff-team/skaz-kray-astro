import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';
import { notifyFamily } from '../lib/notify';

const router = Router();

// GET /poselenie/moye-pomestie
router.get('/', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({
      where: { familyId: req.user!.familyId },
      include: {
        household: {
          include: {
            residents: { orderBy: [{ sort: 'asc' }, { id: 'asc' }] },
            owners: { include: { family: true } },
            cars: { orderBy: { sort: 'asc' } },
            pets: { orderBy: { sort: 'asc' } },
            joinRequests: { include: { family: true } },
          },
        },
      },
    });

    if (!owner) {
      return res.json({ household: null, claimable: true });
    }

    res.json({ household: owner.household });
  } catch (err) {
    console.error('Profile index error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// ── Выбор своего поместья (привязка аккаунта) ───────────────────────────────
// Порт ProfileController::showClaim / showClaimConfirm легаси.

// GET /api/profile/claim — ВСЕ участки (занятые — чтобы проситься совладельцем,
// как listForClaimView), плюс ожидающая заявка текущей семьи.
router.get('/claim', requireAuth, async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const [owner, pending, households] = await Promise.all([
      db.householdOwner.findUnique({ where: { familyId }, select: { id: true } }),
      db.householdJoinRequest.findUnique({
        where: { familyId },
        include: { household: { select: { estateName: true, glade: true, plot: true } } },
      }),
      db.household.findMany({
        orderBy: [{ sort: 'asc' }, { id: 'asc' }],
        include: {
          family: { select: { status: true } },
          _count: { select: { residents: true } },
        },
      }),
    ]);

    res.json({
      hasHousehold: owner !== null,
      pendingJoin: pending
        ? {
            id: pending.id,
            householdId: pending.householdId,
            surname: pending.surname,
            estateName: pending.household.estateName,
            glade: pending.household.glade,
            plot: pending.household.plot,
          }
        : null,
      households: households.map((h) => ({
        id: h.id,
        glade: h.glade,
        plot: h.plot,
        estateName: h.estateName,
        // «Занято» = привязано к активному аккаунту (паритет с listForClaimView).
        occupied: h.familyId !== null && h.family?.status === 'active',
        claimable: h.familyId === null || h.family?.status !== 'active',
        memberCount: h._count.residents,
      })),
    });
  } catch (err) {
    console.error('Profile claim list error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/profile/claim/:id — шаг подтверждения (порт showClaimConfirm).
router.get('/claim/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const id = Number(req.params.id);
    const [owner, h] = await Promise.all([
      db.householdOwner.findUnique({ where: { familyId }, select: { id: true } }),
      db.household.findUnique({
        where: { id },
        include: {
          family: { select: { status: true } },
          _count: { select: { residents: true } },
        },
      }),
    ]);
    if (!h) return res.status(404).json({ error: 'Поместье не найдено' });

    const claimable = h.familyId === null || h.family?.status !== 'active';
    res.json({
      alreadyOwned: owner !== null,
      household: { id: h.id, glade: h.glade, plot: h.plot, estateName: h.estateName },
      hasMembers: h._count.residents > 0,
      claimable,
      occupied: !claimable,
    });
  } catch (err) {
    console.error('Profile claim confirm error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

/**
 * Введённая фамилия есть ли среди жителей поместья: сверяем с КАЖДЫМ словом
 * ФИО без учёта регистра (легаси surnameMatchesHousehold: порядок «Фамилия
 * Имя» / «Имя Фамилия» бывает любым).
 */
function surnameMatches(fullNames: string[], surname: string): boolean {
  const needle = surname.trim().toLowerCase();
  if (needle === '') return false;
  return fullNames.some((full) =>
    full
      .trim()
      .toLowerCase()
      .split(/\s+/)
      .some((tok) => tok === needle),
  );
}

// POST /api/profile/claim/:id — привязать свободное поместье или подать заявку
// в занятое (порт ProfileController::claim: фамилия → claim либо requestJoin).
router.post('/claim/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const familyId = req.user!.familyId;
    const id = Number(req.params.id);
    const surname = String(req.body?.surname ?? '').trim().slice(0, 120);

    const mine = await db.householdOwner.findUnique({
      where: { familyId },
      select: { id: true },
    });
    if (mine) return res.json({ ok: true, alreadyOwned: true });

    const h = await db.household.findUnique({
      where: { id },
      include: {
        family: { select: { status: true } },
        residents: { select: { fullName: true } },
      },
    });
    if (!h) {
      return res.status(404).json({ ok: false, reason: 'missing', error: 'Поместье не найдено' });
    }

    // Принадлежность подтверждаем фамилией, только если есть жители: без них
    // нечем подтвердить (легаси такой участок в списке вообще не открывает).
    if (h.residents.length > 0 && !surnameMatches(h.residents.map((r) => r.fullName), surname)) {
      return res.status(409).json({
        ok: false,
        reason: 'surname',
        error:
          'Такой фамилии нет среди жителей этого поместья. Проверьте написание или обратитесь к редактору.',
      });
    }

    const claimable = h.familyId === null || h.family?.status !== 'active';

    if (claimable) {
      // Первичная привязка (порт HouseholdProfileRepository::claim): прежние
      // привязки аккаунта снимаются, чистятся placeholder-совладельцы импорта,
      // доступность перепроверяется внутри транзакции.
      const claimed = await db.$transaction(async (tx) => {
        const cur = await tx.household.findUnique({
          where: { id },
          select: { familyId: true, family: { select: { status: true } } },
        });
        if (!cur) return false;
        if (cur.familyId !== null && cur.family?.status === 'active') return false;

        await tx.household.updateMany({ where: { familyId }, data: { familyId: null } });
        await tx.householdOwner.deleteMany({ where: { familyId } });
        await tx.householdJoinRequest.deleteMany({ where: { familyId } });
        await tx.householdOwner.deleteMany({ where: { householdId: id } });
        await tx.household.update({
          where: { id },
          data: { familyId, claimSurname: surname || null },
        });
        await tx.householdOwner.create({ data: { householdId: id, familyId } });
        return true;
      });

      if (!claimed) {
        return res
          .status(409)
          .json({ ok: false, reason: 'taken', error: 'Поместье уже занято — выберите другое.' });
      }
      return res.json({ ok: true, claimed: true });
    }

    // Занятое поместье: только заявка владельцу — сама по себе доступа не даёт.
    await db.$transaction([
      db.householdJoinRequest.deleteMany({ where: { familyId } }),
      db.householdJoinRequest.create({ data: { householdId: id, familyId, surname } }),
    ]);

    const [owners, me] = await Promise.all([
      db.householdOwner.findMany({ where: { householdId: id }, select: { familyId: true } }),
      db.family.findUnique({ where: { id: familyId }, select: { name: true } }),
    ]);
    await Promise.all(
      owners.map((o) =>
        notifyFamily(
          o.familyId,
          [
            `Заявка в совладельцы вашего поместья: «${me?.name ?? ''}»${
              surname ? ` (фамилия ${surname})` : ''
            }.`,
            'Если это не ваш родственник — просто отклоните заявку.',
          ],
          '/poselenie/moye-pomestie/',
        ),
      ),
    );

    res.json({ ok: true, requested: true });
  } catch (err) {
    console.error('Profile claim error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/zayavka/prinyat
router.post('/join/approve', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { requestId } = req.body;
    const request = await db.householdJoinRequest.findUnique({
      where: { id: Number(requestId) },
      include: { household: true },
    });

    if (!request || request.household?.familyId !== req.user!.familyId) {
      return res.status(403).json({ error: 'Нет доступа' });
    }

    await db.$transaction([
      db.householdOwner.create({
        data: { householdId: request.householdId, familyId: request.familyId },
      }),
      db.householdJoinRequest.delete({ where: { id: request.id } }),
    ]);

    res.json({ message: 'Заявка подтверждена' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/zayavka/otklonit
router.post('/join/reject', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { requestId } = req.body;
    const request = await db.householdJoinRequest.findUnique({
      where: { id: Number(requestId) },
      include: { household: true },
    });

    if (!request || request.household?.familyId !== req.user!.familyId) {
      return res.status(403).json({ error: 'Нет доступа' });
    }

    await db.householdJoinRequest.delete({ where: { id: request.id } });
    res.json({ message: 'Заявка отклонена' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/profile/join/cancel — отозвать свою заявку (порт cancelJoin).
router.post('/join/cancel', requireAuth, async (req: AuthRequest, res) => {
  try {
    await db.householdJoinRequest.deleteMany({ where: { familyId: req.user!.familyId } });
    res.json({ ok: true });
  } catch (err) {
    console.error('Profile join cancel error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/nazvanie
router.post('/estate', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { name } = req.body;
    const owner = await db.householdOwner.findUnique({
      where: { familyId: req.user!.familyId },
    });

    if (!owner) return res.status(403).json({ error: 'Нет поместья' });

    await db.household.update({
      where: { id: owner.householdId },
      data: { estateName: name },
    });

    res.json({ message: 'Название обновлено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === АВТО ===

// POST /poselenie/moye-pomestie/avto/novyy
router.post('/car/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({ where: { familyId: req.user!.familyId } });
    if (!owner) return res.status(403).json({ error: 'Нет поместья' });

    const car = await db.householdCar.create({
      data: {
        householdId: owner.householdId,
        title: req.body.title,
        plate: req.body.plate || '',
        note: req.body.note || '',
      },
    });

    res.json(car);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/avto/{id}/udalit
router.post('/car/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({ where: { familyId: req.user!.familyId } });
    const car = await db.householdCar.findUnique({ where: { id: Number(req.params.id) } });

    if (!owner || !car || car.householdId !== owner.householdId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.householdCar.delete({ where: { id: car.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// === ПИТОМЦЫ ===

// POST /poselenie/moye-pomestie/pitomec/novyy
router.post('/pet/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({ where: { familyId: req.user!.familyId } });
    if (!owner) return res.status(403).json({ error: 'Нет поместья' });

    const pet = await db.householdPet.create({
      data: {
        householdId: owner.householdId,
        name: req.body.name,
        kind: req.body.kind || req.body.species || '',
        note: req.body.note || '',
      },
    });

    res.json(pet);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/pitomec/{id}/udalit
router.post('/pet/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({ where: { familyId: req.user!.familyId } });
    const pet = await db.householdPet.findUnique({ where: { id: Number(req.params.id) } });

    if (!owner || !pet || pet.householdId !== owner.householdId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.householdPet.delete({ where: { id: pet.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as profileRoutes };
