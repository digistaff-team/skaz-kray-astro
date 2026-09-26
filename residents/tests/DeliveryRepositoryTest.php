<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{DeliveryRepository, TripRepository, FamilyRepository};

final class DeliveryRepositoryTest extends TestCase
{
    private const NOW = '2026-09-26 10:00:00';
    private const TODAY = '2026-09-26';
    private DeliveryRepository $repo;
    private int $req;
    private int $driver;
    private int $other;
    private int $trip;

    protected function setUp(): void
    {
        make_test_db();
        $fam = new FamilyRepository();
        $this->req    = $fam->createPending('req@skaz-kray.ru', 'H', 'Семья Орловых');
        $this->driver = $fam->createPending('drv@skaz-kray.ru', 'H', 'Семья Соколовых');
        $this->other  = $fam->createPending('oth@skaz-kray.ru', 'H', 'Семья Лебедевых');
        $this->trip = (new TripRepository())->create($this->driver, 'Край', 'Северская', '2026-09-28', '10:00', 3, null, self::NOW);
        $this->repo = new DeliveryRepository();
    }

    private function board(?string $needBy = null, string $kind = 'buy'): int
    {
        return $this->repo->create($this->req, null, $kind, 'Хлеб, молоко', 'Магнит', $needBy, '800', null, null, self::NOW);
    }

    public function test_create_on_board_is_open_and_to_trip_is_requested(): void
    {
        $b = $this->board();
        $t = $this->repo->create($this->req, $this->trip, 'pickup', 'Посылка', 'СДЭК', null, null, '4417', 'после обеда', self::NOW);
        $this->assertSame('open', $this->repo->findDetailed($b)['status']);
        $d = $this->repo->findDetailed($t);
        $this->assertSame('requested', $d['status']);
        $this->assertSame('4417', $d['pickup_code']);
        $this->assertSame('Семья Орловых', $d['req_name']);
        $this->assertSame($this->driver, (int) $d['trip_driver_id']);
        $this->assertSame('Северская', $d['destination']);
    }

    public function test_board_lists_only_open_not_expired_and_filters_kind(): void
    {
        $noDate  = $this->board(null);
        $today   = $this->board(self::TODAY);
        $this->board('2026-09-20');                                   // срок прошёл
        $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW); // к поездке
        $pickup  = $this->board(null, 'pickup');

        $ids = array_map('intval', array_column($this->repo->listBoard(self::TODAY), 'id'));
        $this->assertSame([$today, $noDate, $pickup], $ids, 'со сроком — выше, без срока — ниже');
        $this->assertSame([$pickup], array_map('intval', array_column($this->repo->listBoard(self::TODAY, 'pickup'), 'id')));
        $this->assertSame([$today, $noDate], array_map('intval', array_column($this->repo->listBoard(self::TODAY, 'buy'), 'id')), 'buy-фильтр');
        $this->assertSame($ids, array_map('intval', array_column($this->repo->listBoard(self::TODAY, 'xyz'), 'id')), 'неизвестный kind игнорируется — как без фильтра');
    }

    public function test_board_orders_need_by_nearest_first(): void
    {
        $far  = $this->board('2026-10-01');
        $near = $this->board('2026-09-27');
        $ids = array_map('intval', array_column($this->repo->listBoard(self::TODAY), 'id'));
        $this->assertSame([$near, $far], $ids);
    }

    public function test_second_take_fails(): void
    {
        $id = $this->board();
        $this->assertTrue($this->repo->take($id, $this->other, 'open', self::NOW));
        $this->assertFalse($this->repo->take($id, $this->driver, 'open', self::NOW), 'второй не должен перехватить');
        $d = $this->repo->findDetailed($id);
        $this->assertSame('accepted', $d['status']);
        $this->assertSame($this->other, (int) $d['carrier_id']);
        $this->assertSame('Семья Лебедевых', $d['car_name']);
    }

    public function test_driver_takes_or_declines_request(): void
    {
        $a = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->assertFalse($this->repo->take($a, $this->driver, 'open', self::NOW), 'не с того статуса');
        $this->assertTrue($this->repo->take($a, $this->driver, 'requested', self::NOW));

        $b = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->assertTrue($this->repo->decline($b));
        $this->assertSame('declined', $this->repo->findDetailed($b)['status']);
        $this->assertFalse($this->repo->decline($b), 'повторно — нет');
    }

    public function test_list_for_trip_driver_empty_statuses_returns_empty(): void
    {
        $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->assertSame([], $this->repo->listForTripDriver($this->driver, []));
    }

    public function test_list_for_trip_driver_multi_status(): void
    {
        $requested = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $accepted  = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->repo->take($accepted, $this->driver, 'requested', self::NOW);

        $ids = array_map('intval', array_column($this->repo->listForTripDriver($this->driver, ['requested', 'accepted']), 'id'));
        $this->assertEqualsCanonicalizing([$requested, $accepted], $ids);
    }

    public function test_release_carrier_checks_carrier_id(): void
    {
        $id = $this->board();
        $this->repo->take($id, $this->other, 'open', self::NOW);

        $this->assertFalse($this->repo->releaseCarrier($id, $this->driver), 'чужой carrier_id не должен снять');
        $d = $this->repo->findDetailed($id);
        $this->assertSame('accepted', $d['status']);
        $this->assertSame($this->other, (int) $d['carrier_id']);

        $this->assertTrue($this->repo->releaseCarrier($id, $this->other));
        $d = $this->repo->findDetailed($id);
        $this->assertSame('open', $d['status']);
        $this->assertNull($d['carrier_id']);
        $this->assertNull($d['trip_id']);
    }

    public function test_to_board_only_from_declined(): void
    {
        $open = $this->board();
        $this->assertFalse($this->repo->toBoard($open), 'open — уже на доске');

        $accepted = $this->board();
        $this->repo->take($accepted, $this->other, 'open', self::NOW);
        $this->assertFalse($this->repo->toBoard($accepted));

        $declined = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->repo->decline($declined);
        $this->assertTrue($this->repo->toBoard($declined), '«Выложить на доску» после отказа');
        $this->assertSame('open', $this->repo->findDetailed($declined)['status']);
    }

    public function test_deliver_requires_accepted_and_matching_carrier(): void
    {
        $open = $this->board();
        $this->assertFalse($this->repo->deliver($open, $this->other, null, self::NOW), 'ещё не взято (open)');

        $requested = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->assertFalse($this->repo->deliver($requested, $this->driver, null, self::NOW), 'заявка ещё не accepted (requested)');

        $accepted = $this->board();
        $this->repo->take($accepted, $this->other, 'open', self::NOW);
        $this->assertFalse($this->repo->deliver($accepted, $this->driver, null, self::NOW), 'не тот исполнитель');
        $this->assertTrue($this->repo->deliver($accepted, $this->other, '812.50', self::NOW));
        $d = $this->repo->findDetailed($accepted);
        $this->assertSame('delivered', $d['status']);
        $this->assertSame(812.5, (float) $d['receipt_sum']);
    }

    public function test_settle_requires_delivered(): void
    {
        $id = $this->board();
        $this->repo->take($id, $this->other, 'open', self::NOW);
        $this->assertFalse($this->repo->settle($id, self::NOW), 'ещё не привезено (accepted)');
        $this->repo->deliver($id, $this->other, null, self::NOW);
        $this->assertTrue($this->repo->settle($id, self::NOW));
        $this->assertSame('settled', $this->repo->findDetailed($id)['status']);
    }

    public function test_cancel_from_requested_and_accepted(): void
    {
        $requested = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->assertTrue($this->repo->cancel($requested));
        $this->assertSame('cancelled', $this->repo->findDetailed($requested)['status']);
        $this->assertFalse($this->repo->cancel($requested), 'повторно — нет (уже cancelled)');

        $accepted = $this->board();
        $this->repo->take($accepted, $this->other, 'open', self::NOW);
        $this->assertTrue($this->repo->cancel($accepted));
        $this->assertSame('cancelled', $this->repo->findDetailed($accepted)['status']);

        $settled = $this->board();
        $this->repo->take($settled, $this->other, 'open', self::NOW);
        $this->repo->deliver($settled, $this->other, null, self::NOW);
        $this->repo->settle($settled, self::NOW);
        $this->assertFalse($this->repo->cancel($settled), 'settled не отменить');
    }

    public function test_release_trip_requests_returns_affected_and_moves_them_to_board(): void
    {
        $waiting = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $taken   = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->repo->take($taken, $this->driver, 'requested', self::NOW);
        $done    = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->repo->take($done, $this->driver, 'requested', self::NOW);
        $this->repo->deliver($done, $this->driver, null, self::NOW);
        $declined = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $this->repo->decline($declined);

        $affected = $this->repo->releaseTripRequests($this->trip);
        $this->assertEqualsCanonicalizing([$waiting, $taken], array_map('intval', array_column($affected, 'id')));
        $this->assertSame('open', $this->repo->findDetailed($waiting)['status']);
        $this->assertSame('open', $this->repo->findDetailed($taken)['status']);
        $this->assertSame('delivered', $this->repo->findDetailed($done)['status'], 'привезённое не трогаем');
        $this->assertSame('declined', $this->repo->findDetailed($declined)['status'], 'отклонённое не трогаем');
        $this->assertNotContains($declined, array_map('intval', array_column($affected, 'id')));
    }

    public function test_list_by_carrier_excludes_cancelled(): void
    {
        $active = $this->board();
        $this->repo->take($active, $this->other, 'open', self::NOW);
        $cancelled = $this->board();
        $this->repo->take($cancelled, $this->other, 'open', self::NOW);
        $this->repo->cancel($cancelled);

        $ids = array_map('intval', array_column($this->repo->listByCarrier($this->other), 'id'));
        $this->assertSame([$active], $ids);
    }

    public function test_mine_lists(): void
    {
        $mine = $this->board();
        $toTrip = $this->repo->create($this->req, $this->trip, 'buy', 'x', 'y', null, null, null, null, self::NOW);
        $carried = $this->board();
        $this->repo->take($carried, $this->other, 'open', self::NOW);

        $this->assertCount(3, $this->repo->listByRequester($this->req));
        $this->assertSame([$carried], array_map('intval', array_column($this->repo->listByCarrier($this->other), 'id')));
        $incoming = $this->repo->listForTripDriver($this->driver, ['requested']);
        $this->assertSame([$toTrip], array_map('intval', array_column($incoming, 'id')));
        $this->assertSame('active', $incoming[0]['trip_status']);
        $this->assertSame([], $this->repo->listForTripDriver($this->other, ['requested']));
        $this->assertNotContains($mine, array_map('intval', array_column($this->repo->listByCarrier($this->other), 'id')));
    }

    public function test_list_by_requester_filters_by_status_and_empty_means_all(): void
    {
        $open = $this->board();
        $done = $this->board();
        $this->repo->take($done, $this->other, 'open', self::NOW);
        $this->repo->deliver($done, $this->other, '500', self::NOW);

        $this->assertSame([$done], array_map('intval', array_column($this->repo->listByRequester($this->req, ['delivered']), 'id')));
        $this->assertEqualsCanonicalizing([$open, $done], array_map('intval', array_column($this->repo->listByRequester($this->req), 'id')));
        $this->assertEqualsCanonicalizing([$open, $done], array_map('intval', array_column($this->repo->listByRequester($this->req, []), 'id')));
    }
}
