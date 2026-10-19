<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\CouncilDutyRotation;

/**
 * Ротация Дежурного председателя: состав ORDER и адресация по индексу.
 *
 * 19.10.2026 Анастасия Малиновская исключена из состава ПС: её позиция (4)
 * удалена, последующие сдвинуты на 1 — в очереди 11 позиций (0..10).
 * Индекс в council_meeting сдвигает миграция
 * council-duty-rotation-drop-malinovskaya.sql (выполняется ровно один раз).
 */
final class CouncilDutyRotationTest extends TestCase
{
    /** Ушедший из состава не должен попадать ни под одним индексом (в т.ч. по модулю). */
    public function test_removed_member_is_not_in_rotation(): void
    {
        for ($i = -3; $i < 25; $i++) {
            $this->assertStringNotContainsString('Малиновская', CouncilDutyRotation::nameForIndex($i));
        }
    }

    /** Бывшие позиции ≥5 сместились на одну влево: поз. 4 = Максим Жулидов (был 5). */
    public function test_positions_after_removed_one_are_shifted(): void
    {
        $this->assertSame('Ольга Жулидова', CouncilDutyRotation::nameForIndex(0));
        $this->assertSame('Сергей Шубин', CouncilDutyRotation::nameForIndex(3));
        $this->assertSame('Максим Жулидов', CouncilDutyRotation::nameForIndex(4));
        $this->assertSame('Марина Людоговская', CouncilDutyRotation::nameForIndex(6)); // текущий дежурный на прод
        $this->assertSame('Екатерина Шубина', CouncilDutyRotation::nameForIndex(10));
    }

    /** Очередь замыкается по mod 11 — ровно 11 позиций. */
    public function test_rotation_wraps_over_eleven_positions(): void
    {
        $this->assertSame(CouncilDutyRotation::nameForIndex(0), CouncilDutyRotation::nameForIndex(11));
        $this->assertSame(CouncilDutyRotation::nameForIndex(4), CouncilDutyRotation::nameForIndex(15));
    }
}
