<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\DeliveryNotify as N;

final class DeliveryNotifyTest extends TestCase
{
    private function d(array $over = []): array
    {
        return array_merge([
            'id' => 7, 'kind' => 'pickup', 'what' => 'Посылка на имя Орловой', 'place' => 'СДЭК, Северская',
            'need_by' => '2026-09-28', 'pickup_code' => 'SECRET-4417', 'receipt_sum' => null,
            'req_name' => 'Семья Орловых', 'car_name' => 'Семья Лебедевых',
            'origin' => 'Край', 'destination' => 'Северская',
        ], $over);
    }

    /** @return array<int,array<int,string>> */
    private function all(array $d): array
    {
        return [
            N::requestLines($d), N::takenLines($d, 'Семья Лебедевых (Telegram: @leb)'), N::driverDeclinedLines($d),
            N::droppedLines($d), N::unassignedLines($d), N::deliveredLines($d, 2), N::settledLines($d),
            N::cancelledLines($d), N::tripCancelledLines($d),
        ];
    }

    public function test_pickup_code_never_leaks(): void
    {
        foreach ($this->all($this->d()) as $lines) {
            $this->assertStringNotContainsString('SECRET-4417', implode("\n", $lines));
        }
    }

    public function test_request_to_driver(): void
    {
        $t = implode("\n", N::requestLines($this->d()));
        $this->assertStringStartsWith('📦 Просьба привезти', $t);
        $this->assertStringContainsString('Забрать: Посылка на имя Орловой', $t);
        $this->assertStringContainsString('Где: СДЭК, Северская', $t);
        $this->assertStringContainsString('К какому дню: 28 сентября 2026', $t);
        $this->assertStringContainsString('Заказчик: Семья Орловых', $t);
        $this->assertStringContainsString('Поездка: Край → Северская', $t);
    }

    public function test_delivered_mentions_sum_and_receipt_only_when_present(): void
    {
        $with = implode("\n", N::deliveredLines($this->d(['kind' => 'buy', 'receipt_sum' => '812.50']), 2));
        $this->assertStringContainsString('Сумма по чеку: 812.5 ₽', $with);
        $this->assertStringContainsString('Фото чека — в карточке заявки', $with);
        $this->assertStringNotContainsString('tg-media', $with);
        $this->assertStringNotContainsString('uploads', $with);

        $without = implode("\n", N::deliveredLines($this->d(), 0));
        $this->assertStringNotContainsString('Сумма по чеку', $without);
        $this->assertStringNotContainsString('Фото чека', $without);
    }

    public function test_heads(): void
    {
        $d = $this->d();
        $this->assertSame('✅ Заявку взяли', N::takenLines($d, 'x')[0]);
        $this->assertSame('🚫 Водитель не сможет привезти', N::driverDeclinedLines($d)[0]);
        $this->assertSame('↩️ Исполнитель не сможет — заявка снова на доске', N::droppedLines($d)[0]);
        $this->assertSame('↩️ Заказчик снял вас с заявки', N::unassignedLines($d)[0]);
        $this->assertSame('📦 Привезли', N::deliveredLines($d, 0)[0]);
        $this->assertSame('🤝 Получение и расчёт подтверждены', N::settledLines($d)[0]);
        $this->assertSame('🚫 Заявка отменена', N::cancelledLines($d)[0]);
        $this->assertSame('Поездка отменена — ваша заявка теперь на общей доске', N::tripCancelledLines($d)[0]);
    }

    public function test_long_what_is_shortened(): void
    {
        $t = implode("\n", N::requestLines($this->d(['what' => str_repeat('а', 500)])));
        $this->assertLessThan(400, mb_strlen($t));
    }
}
