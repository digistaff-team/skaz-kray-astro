<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\CatalogAnnounce;

/**
 * Анонс новинок каталога: текст сообщения и диплинк мини-приложения.
 * Сама отправка (сеть) здесь не проверяется — только то, что уходит в бота.
 */
final class CatalogAnnounceTest extends TestCase
{
    public function test_deep_link_telegram(): void
    {
        $link = CatalogAnnounce::deepLink('https://t.me/SkazKray_bot/app', '/poselenie/instrumenty/12');
        $this->assertStringStartsWith('https://t.me/SkazKray_bot/app?startapp=', $link);
        $code = substr($link, strlen('https://t.me/SkazKray_bot/app?startapp='));
        $this->assertSame('/poselenie/instrumenty/12', base64_decode(strtr($code, '-_', '+/')));
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9_-]/', $code); // без «=», «+», «/»
    }

    public function test_deep_link_max_keeps_existing_param(): void
    {
        $link = CatalogAnnounce::deepLink('https://max.ru/id643900558807_2_bot?startapp', '/poselenie/knigi/7');
        $this->assertStringStartsWith('https://max.ru/id643900558807_2_bot?startapp=', $link);
        $this->assertStringNotContainsString('startapp=?', $link);
    }

    public function test_text_has_head_card_and_link(): void
    {
        $text = CatalogAnnounce::text('🔧 Новый инструмент в общей копилке', '«Перфоратор» · Электроинструмент', 'https://example.test/x');
        $this->assertStringContainsString('🔧 Новый инструмент в общей копилке', $text);
        $this->assertStringContainsString('«Перфоратор» · Электроинструмент', $text);
        $this->assertStringContainsString('Открыть: https://example.test/x', $text);
        $this->assertLessThanOrEqual(6, substr_count($text, "\n"));   // сообщение остаётся коротким
    }
}
