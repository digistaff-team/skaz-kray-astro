import { Router } from 'express';
import { requireAuth, AuthRequest } from '../middleware/auth';
import { db } from '../lib/db';

const router = Router();

// GET /poselenie/poezdki (доска поездок)
router.get('/board', requireAuth, async (_req: AuthRequest, res) => {
  try {
    const trips = await db.trip.findMany({
      where: { status: 'ACTIVE', tripDate: { gte: new Date() } },
      include: {
        driver: { include: { householdOwners: { include: { household: true } } } },
        bookings: { where: { status: 'CONFIRMED' } },
      },
      orderBy: { tripDate: 'asc' },
    });
    res.json(trips);
  } catch (err) {
    console.error('Trip board error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// GET /poselenie/poezdki/moi
router.get('/mine', requireAuth, async (req: AuthRequest, res) => {
  try {
    const [asDriver, asPassenger] = await Promise.all([
      db.trip.findMany({
        where: { driverId: req.user!.familyId },
        include: { bookings: { include: { passenger: true } } },
        orderBy: { tripDate: 'desc' },
      }),
      db.tripBooking.findMany({
        where: { passengerId: req.user!.familyId },
        include: { trip: { include: { driver: true } } },
        orderBy: { createdAt: 'desc' },
      }),
    ]);
    res.json({ asDriver, asPassenger });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/poezdki/novaya
router.post('/create', requireAuth, async (req: AuthRequest, res) => {
  try {
    const { origin, destination, tripDate, tripTime, seatsTotal, note } = req.body;
    const trip = await db.trip.create({
      data: {
        driverId: req.user!.familyId,
        origin,
        destination,
        tripDate: new Date(tripDate),
        tripTime: tripTime || null,
        seatsTotal: Number(seatsTotal) || 1,
        seatsFree: Number(seatsTotal) || 1,
        note: note || null,
      },
    });
    res.json({ id: trip.id, message: 'Поездка создана' });
  } catch (err) {
    console.error('Trip create error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/poezdki/{id}/bron
router.post('/book/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const trip = await db.trip.findUnique({ where: { id: Number(req.params.id) } });
    if (!trip || trip.status !== 'ACTIVE') {
      return res.status(400).json({ error: 'Поездка недоступна' });
    }

    const seats = Number(req.body.seats) || 1;
    const booking = await db.tripBooking.create({
      data: {
        tripId: trip.id,
        passengerId: req.user!.familyId,
        seats,
        message: req.body.message || null,
      },
    });
    res.json({ id: booking.id, message: 'Заявка отправлена' });
  } catch (err) {
    console.error('Trip book error:', err);
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/poezdki/{id}/zavershit
router.post('/done/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const trip = await db.trip.findUnique({ where: { id: Number(req.params.id) } });
    if (!trip || trip.driverId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.trip.update({ where: { id: trip.id }, data: { status: 'DONE' } });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/poezdki/{id}/otmenit
router.post('/cancel-trip/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const trip = await db.trip.findUnique({ where: { id: Number(req.params.id) } });
    if (!trip || trip.driverId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    const updated = await db.trip.update({ where: { id: trip.id }, data: { status: 'CANCELLED' } });
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/poezdki/{id}/udalit
router.post('/delete/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const trip = await db.trip.findUnique({ where: { id: Number(req.params.id) } });
    if (!trip || trip.driverId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.trip.delete({ where: { id: trip.id } });
    res.json({ message: 'Удалено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/bron/{id}/podtverdit
router.post('/bookings/confirm/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const booking = await db.tripBooking.findUnique({
      where: { id: Number(req.params.id) },
      include: { trip: true },
    });

    if (!booking || booking.trip.driverId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    if (booking.trip.seatsFree < booking.seats) {
      return res.status(400).json({ error: 'Недостаточно мест' });
    }

    await db.$transaction([
      db.tripBooking.update({
        where: { id: booking.id },
        data: { status: 'CONFIRMED', decidedAt: new Date() },
      }),
      db.trip.update({
        where: { id: booking.tripId },
        data: { seatsFree: { decrement: booking.seats } },
      }),
    ]);

    res.json({ message: 'Подтверждено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/bron/{id}/otklonit
router.post('/bookings/decline/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const booking = await db.tripBooking.findUnique({
      where: { id: Number(req.params.id) },
      include: { trip: true },
    });

    if (!booking || booking.trip.driverId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.tripBooking.update({
      where: { id: booking.id },
      data: { status: 'DECLINED', decidedAt: new Date() },
    });
    res.json({ message: 'Отклонено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

// POST /poselenie/bron/{id}/otmenit (пассажир отменяет)
router.post('/bookings/cancel/:id', requireAuth, async (req: AuthRequest, res) => {
  try {
    const booking = await db.tripBooking.findUnique({ where: { id: Number(req.params.id) } });
    if (!booking || booking.passengerId !== req.user!.familyId) {
      return res.status(404).json({ error: 'Не найдено' });
    }

    await db.tripBooking.update({
      where: { id: booking.id },
      data: { status: 'CANCELLED' },
    });
    res.json({ message: 'Отменено' });
  } catch (err) {
    res.status(500).json({ error: 'Ошибка' });
  }
});

export { router as tripRoutes };
