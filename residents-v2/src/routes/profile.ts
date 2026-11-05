import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/moye-pomestie
router.get('/', requireAuth, async (req: AuthRequest, res) => {
  try {
    const owner = await db.householdOwner.findUnique({
      where: { familyId: req.user!.familyId },
      include: {
        household: {
          include: {
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

// GET /poselenie/moye-pomestie/vybor
router.get('/claim', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const households = await db.household.findMany({
      where: { familyId: null },
      orderBy: { estateName: 'asc' },
    });
    res.json(households);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/moye-pomestie/vybor/{id}
router.post('/claim/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { surname } = req.body;
    const household = await db.household.findUnique({
      where: { id: Number(req.params.id) },
    });

    if (!household || household.familyId !== null) {
      return res.status(400).json({ error: 'Поместье недоступно для привязки' });
    }

    if (household.claimSurname && household.claimSurname.toLowerCase() !== String(surname).toLowerCase()) {
      return res.status(403).json({ error: 'Фамилия не совпадает' });
    }

    const familyId = req.user!.familyId;

    await db.$transaction([
      db.household.update({
        where: { id: household.id },
        data: { familyId, claimSurname: String(surname || '') },
      }),
      db.householdOwner.create({
        data: { householdId: household.id, familyId },
      }),
      db.family.update({
        where: { id: familyId },
        data: { status: 'active', approvedAt: new Date() },
      }),
    ]);

    res.json({ message: 'Поместье привязано' });
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
