<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\CouncilDutyNotify;

/**
 * Сообщение новому Дежурному председателю (ротация в понедельник 21:00).
 * Сама отправка (сеть, привязки к боту) здесь не проверяется — только текст, кнопка и ссылка.
 */
final class CouncilDutyNotifyTest extends TestCase
{
    public function test_text_addresses_chair_by_first_name(): void
    {
        $text = CouncilDutyNotify::text('Сергей Шубин', '28 сентября 2026, 18:00', 'Терем', 'https://t.me/x?startapp=y');
        $this->assertStringStartsWith('Сергей, Вы — дежурный председатель', $text);
        $this->assertStringContainsString('28 сентября 2026, 18:00', $text);
        $this->assertStringContainsString('Терем', $text);
        $this->assertStringContainsString('Положении о ПС', $text);
        $this->assertStringEndsWith('https://t.me/x?startapp=y', $text);
    }

    public function test_text_without_name_place_and_date(): void
    {
        $text = CouncilDutyNotify::text('', '', '', 'https://t.me/x');
        $this->assertStringStartsWith('Вы — дежурный председатель', $text);
        $this->assertStringContainsString('Дата встречи уточняется', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);   // пустое место не оставляет дыру
    }

    public function test_ack_button_carries_rotation_index(): void
    {
        $kb = json_decode(CouncilDutyNotify::ackKeyboard(3), true);
        $btn = $kb['inline_keyboard'][0][0];
        $this->assertStringContainsString('Дежурство принял', $btn['text']);
        $this->assertSame('d:ack:3', $btn['callback_data']);
    }

    public function test_link_points_to_council_card(): void
    {
        $link = CouncilDutyNotify::link('https://t.me/SkazKray_bot/sovet');
        $this->assertStringStartsWith('https://t.me/SkazKray_bot/sovet?startapp=', $link);
        $code = substr($link, strlen('https://t.me/SkazKray_bot/sovet?startapp='));
        $this->assertSame('/sovet', base64_decode(strtr($code, '-_', '+/')));
    }
}
