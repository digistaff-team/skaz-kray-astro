<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Controller\DeliveryController as C;

/** Разбор полей формы доставки: «К какому дню» и суммы (примерная, по чеку). */
final class DeliveryControllerInputTest extends TestCase
{
    public function test_accepts_real_dates(): void
    {
        $this->assertTrue(C::validDate('2026-09-30'));
        $this->assertTrue(C::validDate('2028-02-29'));
    }

    public function test_rejects_bad_format_and_impossible_dates(): void
    {
        foreach (['', '2026-9-30', '30.09.2026', '2026-02-30', '2027-02-29', '2026-13-01', '2026-00-10', '2026-04-31', '2026-09-30x'] as $bad) {
            $this->assertFalse(C::validDate($bad), $bad);
        }
    }

    public function test_money_normalizes_russian_input(): void
    {
        $this->assertSame([null, null], C::money(''));
        $this->assertSame([null, null], C::money('   '));
        $this->assertSame(['1250.00', null], C::money('1250'));
        $this->assertSame(['1250.50', null], C::money('1 250,50'));
        $this->assertSame(['1250.00', null], C::money("1\u{00A0}250"));
        $this->assertSame(['1000000.00', null], C::money('1000000'));
        $this->assertSame(['0.50', null], C::money('0,5'));
    }

    public function test_money_rejects_garbage_and_out_of_range(): void
    {
        foreach (['1,250.50', '1000000.01', '-5', 'abc', '12.345', '10000000'] as $bad) {
            [$sum, $err] = C::money($bad);
            $this->assertNull($sum, $bad);
            $this->assertNotNull($err, $bad);
        }
    }
}
