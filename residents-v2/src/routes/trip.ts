import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { requireHousehold } from '../middleware/household';
import { requireSection } from '../middleware/sections';
import { db } from '../lib/db';

const router = Router();

// Гарды легаси (TripController::requireHousehold('poezdki') + RequiresSection).
router.use(requireAuth, requireHousehold, requireSection('poezdki'));

/** 'YYYY-MM-DD' из Date (поле trip_date приходит как полночь UTC). */
function ymd(d: Date): string { return d.toISOString().slice(0, 10); }

/** Нормализация ответа для клиента: дата — строка Y-m-d, FK — числа. */
function tripOut(t: any, extra: Record<string, unknown> = {}) {
  return {
    id: t.id,
    driverId: t.driverId,
    origin: t.origin,
    destination: t.destination,
    tripDate: ymd(t.tripDate),
    tripTime: t.tripTime,
    seatsTotal: t.seatsTotal,
    seatsFree: t.seatsFree,
    note: t.note,
    status: t.status,
    createdAt: t.createdAt,
    ...extra,
  };
}

/**
 * Название семьи водителя по данным легаси — «голова» хозяйства, а не
 * произвольный совладелец: в MySQL households.family_id означает голову
 * (см. HouseholdHead). Если семьи хозяйство не завела — само имя семьи.
 */
function driverName(family: any): string {
  const head = (family?.households ?? [])[0];
  return (head?.name as string) || (family?.name as string) || 'Житель';
}

/**
 * Поиск по названию поместья вводится свободным текстом: экранируем символы
 * LIKE, чтобы '%' в запросе не превращался в заветное «всё».
 */
function likeTerm(s: string): string {
  return '%' + s.replace(/[\\%_]/g, (c) => '\\' + c) + '%';
}

// GET /api/trips/board — доска предстоящих поездок (порт listUpcoming).
// Фильтры: ?q= подстрока по маршруту, ?date=Y-m-d конкретная дата.
router.get('/board', async (req: AuthRequest, res) => {
  try {
    const q = String(req.query.q ?? '').trim();
    const date = String(req.query.date ?? '').trim();
    const today = new Date(); today.setUTCHours(0, 0, 0, 0);

    const where: any = { status: 'active', tripDate: { gte: today }, seatsFree: { gt: 0 } };
    if (date) {
      const from = new Date(date + 'T00:00:00.000Z');
      if (!isNaN(from.getTime())) {
        where.tripDate = { gte: from, lte: new Date(from.getTime() + 86400000 - 1) };
      }
    }
    const trips = await db.trip.findMany({
      where,
      include: {
        driver: { include: { householdOwners: { include: { household: true } } } },
      },
      orderBy: [{ tripDate: 'asc' }, { tripTime: 'asc' }],
    });

    const term = q.toLowerCase();
    const rows = trips
      .filter((t) => !term
        || t.origin.toLowerCase().includes(term)
        || t.destination.toLowerCase().includes(term))
      .map((t) => tripOut(t, { driverName: driverName(t.driver) }));

    res.json(rows);
  } catch (err) {
    console.error('Trip board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/trips/mine — «Мои поездки»: где я водитель (с бронями) и где я
// пассажир (с водителем и его именем).
router.get('/mine', async (req: AuthRequest, res) => {
  const familyId = req.user!.familyId;
  try {
    const [asDriver, asPassenger] = await Promise.all([
      db.trip.findMany({
        where: { driverId: familyId },
        include: {
          bookings: {
            include: {
              passenger: { include: { householdOwners: { include: { household: true } } } },
            },
            orderBy: { createdAt: 'asc' },
          },
        },
        orderBy: [{ tripDate: 'desc' }, { id: 'desc' }],
      }),
      db.tripBooking.findMany({
        where: { passengerId: familyId },
        include: {
          trip: { include: { driver: { include: { householdOwners: { include: { household: true } } } } } },
        },
        orderBy: { createdAt: 'desc' },
      }),
    ]);

    res.json({
      asDriver: asDriver.map((t) => tripOut(t, {
        bookings: t.bookings.map((b) => ({
          id: b.id,
          passengerName: driverName(b.passenger),
          seats: b.seats,
          status: b.status,
          message: b.message,
          createdAt: b.createdAt,
        })),
      })),
      asPassenger: asPassenger.map((b) => ({
        id: b.id,
        seats: b.seats,
        status: b.status,
        createdAt: b.createdAt,
        trip: b.trip ? tripOut(b.trip, { driverName: driverName(b.trip.driver) }) : null,
      })),
    });
  } catch (err) {
    console.error('Trip mine error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /api/trips/:id — одна поездка (детально) для карточки/брони.
// Водитель и данные этой семьи видны только вошедшим с поместьем (маршрут уже
// под requireHousehold). Брони водителю не отдаём: их ведёт раздел «Мои».
router.get('/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    if (!Number.isInteger(id) || id <= 0) return res.status(404).json({ error: 'Не найдено' });

    const trip = await db.trip.findUnique({
      where: { id },
      include: {
        driver: { include: { householdOwners: { include: { household: true } } } },
      },
    });
    if (!trip) return res.status(404).json({ error: 'Не найдено' });

    const familyId = req.user!.familyId;
    const isDriver = trip.driverId === familyId;
    let myBooking = null;
    if (!isDriver) {
      myBooking = await db.tripBooking.findFirst({
        where: { tripId: id, passengerId: familyId, status: { in: ['requested', 'confirmed'] } },
        orderBy: { createdAt: 'desc' },
      });
    }

    res.json(tripOut(trip, {
      driverName: driverName(trip.driver),
      isDriver,
      myBooking: myBooking
        ? { id: myBooking.id, seats: myBooking.seats, status: myBooking.status }
        : null,
    }));
  } catch (err) {
    console.error('Trip show error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/create — водитель публикует поездку.
router.post('/create', async (req: AuthRequest, res) => {
  try {
    const { origin, destination, tripDate, tripTime, seatsTotal, note } = req.body;
    const o = String(origin ?? '').trim();
    const d = String(destination ?? '').trim();
    if (!o || !d) return res.status(400).json({ error: 'Укажите откуда и куда' });
    if (o.length > 160 || d.length > 160) return res.status(400).json({ error: 'Слишком длинный маршрут' });

    const date = new Date(String(tripDate ?? '') + 'T00:00:00.000Z');
    if (isNaN(date.getTime())) return res.status(400).json({ error: 'Укажите дату поездки' });
    const today = new Date(); today.setUTCHours(0, 0, 0, 0);
    if (date < today) return res.status(400).json({ error: 'Дата поездки уже в прошлом' });

    const seats = Math.max(1, Math.min(8, Math.floor(Number(seatsTotal) || 1)));

    const trip = await db.trip.create({
      data: {
        driverId: req.user!.familyId,
        origin: o,
        destination: d,
        tripDate: date,
        tripTime: tripTime ? String(tripTime).slice(0, 40) : null,
        seatsTotal: seats,
        seatsFree: seats,
        note: note ? String(note).slice(0, 500) : null,
      },
    });
    res.json({ id: trip.id, message: 'Поездка опубликована' });
  } catch (err) {
    console.error('Trip create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/:id/zavershit — водитель отметил поездку состоявшейся.
// Незавершённые просьбы на доставку к этой поездке освобождаются: поездка
// прошла, водитель их уже не возьмёт (порт releaseTripRequests(['requested'])
// из TripController::done).
router.post('/zavershit/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const trip = await db.trip.findUnique({ where: { id } });
    if (!trip || trip.driverId !== req.user!.familyId) return res.status(404).json({ error: 'Не найдено' });
    if (trip.status !== 'active') return res.status(400).json({ error: 'Поездка уже закрыта' });

    const [updated] = await db.$transaction([
      db.trip.update({ where: { id }, data: { status: 'done' } }),
      db.delivery.updateMany({
        where: { tripId: id, status: 'requested' },
        data: { status: 'open', carrierId: null, tripId: null, acceptedAt: null },
      }),
    ]);
    res.json(tripOut(updated));
  } catch (err) {
    console.error('Trip done error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/:id/otmenit — водитель отменил поездку.
// Просьбы «привезти» к этой поездке возвращаются на общую доску доставки
// (порт releaseTripRequests(['requested','accepted'])).
router.post('/otmenit/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const trip = await db.trip.findUnique({ where: { id } });
    if (!trip || trip.driverId !== req.user!.familyId) return res.status(404).json({ error: 'Не найдено' });

    const [updated] = await db.$transaction([
      db.trip.update({ where: { id }, data: { status: 'cancelled' } }),
      db.delivery.updateMany({
        where: { tripId: id, status: { in: ['requested', 'accepted'] } },
        data: { status: 'open', carrierId: null, tripId: null, acceptedAt: null },
      }),
    ]);
    res.json(tripOut(updated));
  } catch (err) {
    console.error('Trip cancel error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/:id/udalit — удалить свою поездку (брони уйдут каскадом).
router.post('/udalit/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const trip = await db.trip.findUnique({ where: { id } });
    if (!trip || trip.driverId !== req.user!.familyId) return res.status(404).json({ error: 'Не найдено' });

    await db.$transaction([
      db.delivery.updateMany({
        where: { tripId: id, status: { in: ['requested', 'accepted'] } },
        data: { status: 'open', carrierId: null, tripId: null, acceptedAt: null },
      }),
      db.trip.delete({ where: { id } }),
    ]);
    res.json({ message: 'Поездка удалена' });
  } catch (err) {
    console.error('Trip delete error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// ---- Брони пассажиров ----

// POST /api/trips/:id/bron — пассажир просит место, водитель решит.
router.post('/bron/:id', async (req: AuthRequest, res) => {
  try {
    const id = Number(req.params.id);
    const familyId = req.user!.familyId;
    const trip = await db.trip.findUnique({ where: { id } });
    if (!trip || trip.status !== 'active') return res.status(400).json({ error: 'Поездка недоступна для брони' });
    if (trip.driverId === familyId) return res.status(400).json({ error: 'Это ваша поездка' });

    const today = new Date(); today.setUTCHours(0, 0, 0, 0);
    if (trip.tripDate < today) return res.status(400).json({ error: 'Поездка уже прошла' });

    const seats = Math.max(1, Math.min(trip.seatsFree, Math.floor(Number(req.body.seats) || 1)));
    if (seats < 1) return res.status(400).json({ error: 'Свободных мест нет' });

    const existing = await db.tripBooking.findFirst({
      where: { tripId: id, passengerId: familyId, status: 'requested' },
    });
    if (existing) return res.status(400).json({ error: 'Заявка уже отправлена — ждите ответа водителя' });

    const booking = await db.tripBooking.create({
      data: {
        tripId: id,
        passengerId: familyId,
        seats,
        message: req.body.message ? String(req.body.message).slice(0, 500) : null,
      },
    });
    res.json({ id: booking.id, message: 'Заявка отправлена водителю' });
  } catch (err) {
    console.error('Trip book error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/bookings/confirm/:id — водитель подтверждает бронь.
// Места списываем условным UPDATE (seatsFree >= seats), а не чтением до
// проверки: два подтверждения одновременно не увезут места в минус.
router.post('/bookings/confirm/:id', async (req: AuthRequest, res) => {
  try {
    const bookingId = Number(req.params.id);
    const familyId = req.user!.familyId;
    const booking = await db.tripBooking.findUnique({
      where: { id: bookingId },
      include: { trip: true },
    });
    if (!booking || booking.trip.driverId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (booking.status !== 'requested') return res.status(400).json({ error: 'Заявка уже обработана' });

    const taken = await db.trip.updateMany({
      where: { id: booking.tripId, status: 'active', seatsFree: { gte: booking.seats } },
      data: { seatsFree: { decrement: booking.seats } },
    });
    if (taken.count === 0) return res.status(400).json({ error: 'Недостаточно свободных мест' });

    await db.tripBooking.update({
      where: { id: bookingId },
      data: { status: 'confirmed', decidedAt: new Date() },
    });
    res.json({ message: 'Бронь подтверждена' });
  } catch (err) {
    console.error('Trip booking confirm error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/bookings/decline/:id — водитель отклоняет бронь.
router.post('/bookings/decline/:id', async (req: AuthRequest, res) => {
  try {
    const bookingId = Number(req.params.id);
    const familyId = req.user!.familyId;
    const booking = await db.tripBooking.findUnique({
      where: { id: bookingId },
      include: { trip: true },
    });
    if (!booking || booking.trip.driverId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (booking.status !== 'requested') return res.status(400).json({ error: 'Заявка уже обработана' });

    await db.tripBooking.update({
      where: { id: bookingId },
      data: { status: 'declined', decidedAt: new Date() },
    });
    res.json({ message: 'Бронь отклонена' });
  } catch (err) {
    console.error('Trip booking decline error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /api/trips/bookings/cancel/:id — пассажир отменяет свою бронь.
// У подтверждённой заявки возвращаем занятые места поездке (не выше всего мест).
router.post('/bookings/cancel/:id', async (req: AuthRequest, res) => {
  try {
    const bookingId = Number(req.params.id);
    const familyId = req.user!.familyId;
    const booking = await db.tripBooking.findUnique({
      where: { id: bookingId },
      include: { trip: true },
    });
    if (!booking || booking.passengerId !== familyId) return res.status(404).json({ error: 'Не найдено' });
    if (!['requested', 'confirmed'].includes(booking.status)) {
      return res.status(400).json({ error: 'Заявку уже не отменить' });
    }
    const seatsFreeAtLoad = booking.trip.seatsFree;

    await db.$transaction(async (tx) => {
      const changed = await tx.tripBooking.updateMany({
        where: { id: bookingId, status: booking.status },
        data: { status: 'cancelled' },
      });
      // Гонка (водитель успел подтвердить/отклонить) — не трогаем места.
      if (!changed.count || booking.status !== 'confirmed') return;

      const trip = await tx.trip.findUnique({ where: { id: booking.tripId }, select: { seatsTotal: true } });
      const cap = trip?.seatsTotal ?? 0;
      await tx.trip.update({
        where: { id: booking.tripId },
        data: { seatsFree: Math.min(cap, seatsFreeAtLoad + booking.seats) },
      });
    });
    res.json({ message: 'Бронь отменена' });
  } catch (err) {
    console.error('Trip booking cancel error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as tripRoutes };
