<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{TripRepository, FamilyRepository};

final class TripRepositoryTest extends TestCase
{
    private TripRepository $trips;
    private int $driver;

    protected function setUp(): void
    {
        make_test_db();
        $this->driver = (new FamilyRepository())->createPending('driver@skaz-kray.ru', 'H', 'Поместье Водителя');
        $this->trips = new TripRepository();
    }

    public function test_create_defaults(): void
    {
        $id = $this->trips->create($this->driver, 'Сказочный Край', 'Краснодар', '2099-01-01', '09:00', 3, 'за бензин', '2026-08-29 10:00:00');
        $t = $this->trips->findById($id);
        $this->assertSame('Сказочный Край', $t['origin']);
        $this->assertSame('active', $t['status']);
        $this->assertSame(3, (int) $t['seats_total']);
        $this->assertSame(3, (int) $t['seats_free']);
    }

    public function test_adjust_seats_not_below_zero(): void
    {
        $id = $this->trips->create($this->driver, 'A', 'B', '2099-01-01', null, 2, null, '2026-08-29 10:00:00');
        $this->trips->adjustSeats($id, -1);
        $this->assertSame(1, (int) $this->trips->findById($id)['seats_free']);
        $this->trips->adjustSeats($id, -5); // не уходит ниже 0
        $this->assertSame(0, (int) $this->trips->findById($id)['seats_free']);
        $this->trips->adjustSeats($id, 2);
        $this->assertSame(2, (int) $this->trips->findById($id)['seats_free']);
    }

    public function test_upcoming_board_filters(): void
    {
        $today = date('Y-m-d');
        $future = date('Y-m-d', strtotime('+3 days'));
        $past   = date('Y-m-d', strtotime('-3 days'));

        $a = $this->trips->create($this->driver, 'Край', 'Краснодар', $future, '09:00', 2, null, '2026-08-29 10:00:00');
        $b = $this->trips->create($this->driver, 'Край', 'Афипская', $future, '10:00', 1, null, '2026-08-29 10:00:00');
        $old = $this->trips->create($this->driver, 'Край', 'Краснодар', $past, '09:00', 2, null, '2026-08-29 10:00:00');
        $full = $this->trips->create($this->driver, 'Край', 'Горячий Ключ', $future, '09:00', 1, null, '2026-08-29 10:00:00');
        $this->trips->adjustSeats($full, -1); // мест не осталось
        $cancelled = $this->trips->create($this->driver, 'Край', 'Краснодар', $future, '09:00', 2, null, '2026-08-29 10:00:00');
        $this->trips->setStatus($cancelled, 'cancelled');

        // На доске: только будущие, активные, со свободными местами (a и b).
        $board = $this->trips->listUpcoming($today);
        $ids = array_map(fn($r) => (int) $r['id'], $board);
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);
        $this->assertNotContains($old, $ids);
        $this->assertNotContains($full, $ids);
        $this->assertNotContains($cancelled, $ids);

        // Поиск по маршруту.
        $byRoute = $this->trips->listUpcoming($today, 'Афипская');
        $this->assertCount(1, $byRoute);
        $this->assertSame($b, (int) $byRoute[0]['id']);

        // Фильтр по дате.
        $this->assertCount(2, $this->trips->listUpcoming($today, '', $future));
    }

    public function test_list_by_driver_and_delete(): void
    {
        $id = $this->trips->create($this->driver, 'A', 'B', '2099-01-01', null, 1, null, '2026-08-29 10:00:00');
        $this->assertCount(1, $this->trips->listByDriver($this->driver));
        $this->trips->delete($id);
        $this->assertNull($this->trips->findById($id));
    }
}
