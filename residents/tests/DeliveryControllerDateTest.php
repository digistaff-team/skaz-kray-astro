<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Controller\DeliveryController;

/** «К какому дню»: формат Y-m-d и настоящая календарная дата. */
final class DeliveryControllerDateTest extends TestCase
{
    public function test_accepts_real_dates(): void
    {
        $this->assertTrue(DeliveryController::validDate('2026-09-30'));
        $this->assertTrue(DeliveryController::validDate('2028-02-29'));
    }

    public function test_rejects_bad_format_and_impossible_dates(): void
    {
        foreach (['', '2026-9-30', '30.09.2026', '2026-02-30', '2027-02-29', '2026-13-01', '2026-00-10', '2026-04-31', '2026-09-30x'] as $bad) {
            $this->assertFalse(DeliveryController::validDate($bad), $bad);
        }
    }
}
