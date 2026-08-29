<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{TripBookingRepository, TripRepository, FamilyRepository};

final class TripBookingRepositoryTest extends TestCase
{
    private TripBookingRepository $bookings;
    private TripRepository $trips;
    private int $driver;
    private int $passenger;
    private int $tripId;

    protected function setUp(): void
    {
        make_test_db();
        $fam = new FamilyRepository();
        $this->driver    = $fam->createPending('driver@skaz-kray.ru', 'H', 'Водитель');
        $this->passenger = $fam->createPending('rider@skaz-kray.ru', 'H', 'Пассажир');
        $this->trips = new TripRepository();
        $this->tripId = $this->trips->create($this->driver, 'Край', 'Краснодар', '2099-01-01', '09:00', 3, null, '2026-08-29 10:00:00');
        $this->bookings = new TripBookingRepository();
    }

    public function test_book_creates_requested_and_active_lookup(): void
    {
        $id = $this->bookings->create($this->tripId, $this->passenger, 2, 'встретимся у ворот', '2026-08-29 10:00:00');
        $b = $this->bookings->findById($id);
        $this->assertSame('requested', $b['status']);
        $this->assertSame(2, (int) $b['seats']);
        $active = $this->bookings->activeForTripAndPassenger($this->tripId, $this->passenger);
        $this->assertSame($id, (int) $active['id']);
    }

    public function test_confirm_flow_decrements_seats_on_trip(): void
    {
        $id = $this->bookings->create($this->tripId, $this->passenger, 2, null, '2026-08-29 10:00:00');
        // Контроллер списывает места сам; репозиторий фиксирует статус + дату.
        $this->bookings->setStatus($id, 'confirmed', '2026-08-29 11:00:00');
        $this->trips->adjustSeats($this->tripId, -2);
        $this->assertSame('confirmed', $this->bookings->findById($id)['status']);
        $this->assertSame(1, (int) $this->trips->findById($this->tripId)['seats_free']);
        $this->assertSame(2, $this->bookings->confirmedSeats($this->tripId));
    }

    public function test_decline_and_cancel(): void
    {
        $d = $this->bookings->create($this->tripId, $this->passenger, 1, null, '2026-08-29 10:00:00');
        $this->bookings->setStatus($d, 'declined', '2026-08-29 11:00:00');
        $this->assertSame('declined', $this->bookings->findById($d)['status']);
        $this->assertNull($this->bookings->activeForTripAndPassenger($this->tripId, $this->passenger));

        $c = $this->bookings->create($this->tripId, $this->passenger, 1, null, '2026-08-29 12:00:00');
        $this->bookings->setStatus($c, 'cancelled');
        $this->assertSame('cancelled', $this->bookings->findById($c)['status']);
    }

    public function test_detailed_join_and_lists(): void
    {
        $id = $this->bookings->create($this->tripId, $this->passenger, 1, null, '2026-08-29 10:00:00');
        $d = $this->bookings->findDetailed($id);
        $this->assertSame($this->driver, (int) $d['driver_id']);
        $this->assertSame('Водитель', $d['driver_name']);
        $this->assertSame('Пассажир', $d['passenger_name']);
        $this->assertSame('rider@skaz-kray.ru', $d['passenger_email']);
        $this->assertSame(3, (int) $d['trip_seats_free']);

        $this->assertCount(1, $this->bookings->listForTrip($this->tripId));
        $this->assertCount(1, $this->bookings->listIncoming($this->driver, ['requested']));
        $this->assertCount(1, $this->bookings->listByPassenger($this->passenger));
    }
}
