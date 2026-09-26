<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\DeliveryPolicy as P;

/**
 * Кто что может с заявкой на доставку в каждом статусе. Эти же правила решают,
 * какие кнопки показывать, и проверяют каждое действие в контроллере.
 */
final class DeliveryPolicyTest extends TestCase
{
    private const REQ = 1, DRIVER = 2, CARRIER = 3, OTHER = 4;

    /** @param array<string,mixed> $over */
    private function d(string $status, array $over = []): array
    {
        return array_merge([
            'requester_id' => self::REQ, 'carrier_id' => null, 'trip_id' => null,
            'kind' => 'buy', 'status' => $status,
        ], $over);
    }

    private function acts(array $d, int $me, ?int $driver = null, int $receipts = 0): array
    {
        $a = P::actions($d, $me, $driver, $receipts);
        sort($a);
        return $a;
    }

    public function test_requested_driver_takes_or_declines_requester_cancels(): void
    {
        $d = $this->d('requested', ['trip_id' => 10]);
        $this->assertSame([P::DECLINE, P::TAKE], $this->acts($d, self::DRIVER, self::DRIVER));
        $this->assertSame([P::CANCEL], $this->acts($d, self::REQ, self::DRIVER));
        $this->assertSame([], $this->acts($d, self::OTHER, self::DRIVER));
    }

    public function test_open_anyone_but_requester_takes(): void
    {
        $d = $this->d('open');
        $this->assertSame([P::TAKE], $this->acts($d, self::OTHER));
        $this->assertSame([P::TAKE], $this->acts($d, self::DRIVER));
        $this->assertSame([P::CANCEL], $this->acts($d, self::REQ));
    }

    public function test_accepted_carrier_drops_or_delivers_requester_unassigns_or_cancels(): void
    {
        $d = $this->d('accepted', ['carrier_id' => self::CARRIER]);
        $this->assertSame([P::DELIVER, P::DROP], $this->acts($d, self::CARRIER));
        $this->assertSame([P::CANCEL, P::UNASSIGN], $this->acts($d, self::REQ));
        $this->assertSame([], $this->acts($d, self::OTHER));
    }

    public function test_delivered_requester_settles_carrier_adds_receipt_for_buy_only(): void
    {
        $buy = $this->d('delivered', ['carrier_id' => self::CARRIER]);
        $this->assertSame([P::SETTLE], $this->acts($buy, self::REQ));
        $this->assertSame([P::ADD_RECEIPT], $this->acts($buy, self::CARRIER, null, 2));
        $this->assertSame([], $this->acts($buy, self::CARRIER, null, 3), 'не больше 3 фото');
        $pickup = $this->d('delivered', ['carrier_id' => self::CARRIER, 'kind' => 'pickup']);
        $this->assertSame([], $this->acts($pickup, self::CARRIER));
    }

    public function test_declined_requester_moves_to_board(): void
    {
        $d = $this->d('declined', ['trip_id' => 10]);
        $this->assertSame([P::TO_BOARD], $this->acts($d, self::REQ, self::DRIVER));
        $this->assertSame([], $this->acts($d, self::DRIVER, self::DRIVER));
    }

    public function test_final_statuses_have_no_actions(): void
    {
        foreach (['settled', 'cancelled'] as $s) {
            $d = $this->d($s, ['carrier_id' => self::CARRIER]);
            foreach ([self::REQ, self::CARRIER, self::OTHER] as $me) {
                $this->assertSame([], $this->acts($d, $me), "{$s} для {$me}");
            }
        }
    }

    public function test_allows_matches_actions(): void
    {
        $d = $this->d('open');
        $this->assertTrue(P::allows(P::TAKE, $d, self::OTHER, null));
        $this->assertFalse(P::allows(P::TAKE, $d, self::REQ, null));
    }

    public function test_private_parts_visible_only_to_the_parties(): void
    {
        $d = $this->d('accepted', ['carrier_id' => self::CARRIER]);
        $this->assertTrue(P::seesPrivate($d, self::REQ));
        $this->assertTrue(P::seesPrivate($d, self::CARRIER));
        $this->assertFalse(P::seesPrivate($d, self::OTHER));
        $this->assertFalse(P::seesPrivate($this->d('open'), self::OTHER), 'исполнителя ещё нет');
    }

    /** @param array<string,mixed> $d @param array<int,string> $expected */
    #[DataProvider('matrix')]
    public function test_role_status_matrix(array $d, int $me, ?int $driver, array $expected): void
    {
        $this->assertSame($expected, $this->acts($d, $me, $driver));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:int,2:?int,3:array<int,string>}> */
    public static function matrix(): iterable
    {
        $roles = ['REQ' => self::REQ, 'DRIVER' => self::DRIVER, 'CARRIER' => self::CARRIER, 'OTHER' => self::OTHER];

        $cases = [
            'requested' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => null, 'trip_id' => 10, 'kind' => 'buy', 'status' => 'requested'],
                'driver' => self::DRIVER,
                'expected' => ['REQ' => [P::CANCEL], 'DRIVER' => [P::DECLINE, P::TAKE], 'CARRIER' => [], 'OTHER' => []],
            ],
            'open' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => null, 'trip_id' => null, 'kind' => 'buy', 'status' => 'open'],
                'driver' => null,
                'expected' => ['REQ' => [P::CANCEL], 'DRIVER' => [P::TAKE], 'CARRIER' => [P::TAKE], 'OTHER' => [P::TAKE]],
            ],
            'accepted' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => self::CARRIER, 'trip_id' => 10, 'kind' => 'buy', 'status' => 'accepted'],
                'driver' => self::DRIVER,
                'expected' => ['REQ' => [P::CANCEL, P::UNASSIGN], 'DRIVER' => [], 'CARRIER' => [P::DELIVER, P::DROP], 'OTHER' => []],
            ],
            'delivered' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => self::CARRIER, 'trip_id' => null, 'kind' => 'buy', 'status' => 'delivered'],
                'driver' => null,
                'expected' => ['REQ' => [P::SETTLE], 'DRIVER' => [], 'CARRIER' => [P::ADD_RECEIPT], 'OTHER' => []],
            ],
            'settled' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => self::CARRIER, 'trip_id' => null, 'kind' => 'buy', 'status' => 'settled'],
                'driver' => null,
                'expected' => ['REQ' => [], 'DRIVER' => [], 'CARRIER' => [], 'OTHER' => []],
            ],
            'declined' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => null, 'trip_id' => 10, 'kind' => 'buy', 'status' => 'declined'],
                'driver' => self::DRIVER,
                'expected' => ['REQ' => [P::TO_BOARD], 'DRIVER' => [], 'CARRIER' => [], 'OTHER' => []],
            ],
            'cancelled' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => self::CARRIER, 'trip_id' => null, 'kind' => 'buy', 'status' => 'cancelled'],
                'driver' => null,
                'expected' => ['REQ' => [], 'DRIVER' => [], 'CARRIER' => [], 'OTHER' => []],
            ],
            'bogus' => [
                'd' => ['requester_id' => self::REQ, 'carrier_id' => self::CARRIER, 'trip_id' => null, 'kind' => 'buy', 'status' => 'bogus'],
                'driver' => null,
                'expected' => ['REQ' => [], 'DRIVER' => [], 'CARRIER' => [], 'OTHER' => []],
            ],
        ];

        $rows = [];
        foreach ($cases as $status => $case) {
            foreach ($roles as $roleName => $roleId) {
                $expected = $case['expected'][$roleName];
                sort($expected);
                $rows["{$status} / {$roleName}"] = [$case['d'], $roleId, $case['driver'], $expected];
            }
        }
        return $rows;
    }

    public function test_requested_ignores_trip_driver_when_trip_id_is_null(): void
    {
        $d = $this->d('requested', ['trip_id' => null]);
        $this->assertSame([], $this->acts($d, self::DRIVER, self::DRIVER), 'заявка не к поездке — водитель роли не даёт');
    }

    public function test_accepted_gives_no_actions_to_a_driver_who_is_not_the_carrier(): void
    {
        $d = $this->d('accepted', ['carrier_id' => null, 'trip_id' => null]);
        $this->assertSame([], $this->acts($d, self::DRIVER, self::DRIVER));
    }

    public function test_requester_who_is_also_the_trip_driver_only_cancels(): void
    {
        $d = $this->d('requested', ['requester_id' => self::DRIVER, 'trip_id' => 10]);
        $this->assertSame([P::CANCEL], $this->acts($d, self::DRIVER, self::DRIVER));
    }

    public function test_accepts_pdo_style_string_ids(): void
    {
        $d = $this->d('accepted', ['requester_id' => '1', 'carrier_id' => '3', 'trip_id' => '10']);
        $this->assertSame([P::CANCEL, P::UNASSIGN], $this->acts($d, self::REQ));
        $this->assertSame([P::DELIVER, P::DROP], $this->acts($d, self::CARRIER));
        $this->assertTrue(P::seesPrivate($d, self::REQ));
        $this->assertTrue(P::seesPrivate($d, self::CARRIER));
        $this->assertFalse(P::seesPrivate($d, self::OTHER));
    }

    public function test_labels(): void
    {
        $this->assertSame('на доске', delivery_status_label('open'));
        $this->assertSame('ждёт водителя', delivery_status_label('requested'));
        $this->assertSame('Купить', delivery_kind_label('buy'));
        $this->assertSame('Забрать', delivery_kind_label('pickup'));
        $this->assertSame('tool-st--free', delivery_status_class('open'));
    }
}
