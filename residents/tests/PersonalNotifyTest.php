<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\BotNotify;

/**
 * Личные уведомления: текст сообщения бота и строка «как связаться».
 *
 * Отправку (сеть, AfterResponse) тут не проверить, поэтому стережём то, что
 * житель видит: из чего собран текст и что в контакт никогда не попадает
 * служебный адрес жителя из мессенджера.
 */
final class PersonalNotifyTest extends TestCase
{
    public function test_text_is_lines_blank_line_and_deep_link(): void
    {
        $text = BotNotify::personalText(
            ['🔧 Заявка на инструмент', '', '«Дрель»', 'Житель: Семья Шубиных'],
            'Одобрить или отклонить: ',
            'https://t.me/SkazKray_bot/app',
            '/poselenie/instrumenty/moi'
        );
        $lines = explode("\n", $text);
        $this->assertSame('🔧 Заявка на инструмент', $lines[0]);
        $this->assertSame('Житель: Семья Шубиных', $lines[3]);
        $this->assertSame('', $lines[4]);   // ссылка отделена пустой строкой
        $this->assertStringStartsWith('Одобрить или отклонить: https://t.me/SkazKray_bot/app?startapp=', $lines[5]);

        // Диплинк ведёт ровно на нужную страницу портала.
        $code = substr($lines[5], strpos($lines[5], 'startapp=') + 9);
        $this->assertSame('/poselenie/instrumenty/moi', base64_decode(strtr($code, '-_', '+/')));
    }

    public function test_text_uses_max_link_format_too(): void
    {
        // В MAX параметр уже в базе ссылки («…?startapp»), диплинк дописывает «=код».
        $text = BotNotify::personalText(['✅'], 'Мои поездки: ', 'https://max.ru/bot?startapp', '/poselenie/poezdki/moi');
        $this->assertStringContainsString('Мои поездки: https://max.ru/bot?startapp=', $text);
    }

    public function test_contact_prefers_telegram_handle(): void
    {
        $this->assertSame('Иван Петров (Telegram: @ivan)', family_contact('Иван Петров', 'ivan', 'tg1@telegram.local'));
        $this->assertSame('Иван Петров (Telegram: @ivan)', family_contact('Иван Петров', '@ivan', 'ivan@mail.ru'));
    }

    public function test_contact_falls_back_to_real_email(): void
    {
        $this->assertSame('Анна (почта: anna@mail.ru)', family_contact('Анна', null, 'anna@mail.ru'));
        $this->assertSame('Анна (почта: anna@mail.ru)', family_contact('Анна', '', 'anna@mail.ru'));
    }

    public function test_contact_never_shows_service_address(): void
    {
        // Раньше в письмах стояло «Контакт владельца: tg123@telegram.local».
        $this->assertSame('Семья Шубиных', family_contact('Семья Шубиных', null, 'tg123@telegram.local'));
        $this->assertSame('Семья Шубиных', family_contact('Семья Шубиных', null, 'max9@max.local'));
    }
}
