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

    public function test_deep_link_for_product_card(): void
    {
        // Кнопка под анонсом ведёт на карточку самого товара, а не в ленту.
        $link = CatalogAnnounce::deepLink('https://t.me/SkazKray_bot/app', '/poselenie/yarmarka/5');
        $code = substr($link, strlen('https://t.me/SkazKray_bot/app?startapp='));
        $this->assertSame('/poselenie/yarmarka/5', base64_decode(strtr($code, '-_', '+/')));
    }

    public function test_deep_link_for_diary_entry(): void
    {
        $link = CatalogAnnounce::deepLink('https://t.me/SkazKray_bot/app', '/poselenie/dnevniki/5');
        $code = substr($link, strlen('https://t.me/SkazKray_bot/app?startapp='));
        $this->assertSame('/poselenie/dnevniki/5', base64_decode(strtr($code, '-_', '+/')));
    }

    public function test_trip_line_has_route_when_and_seats(): void
    {
        $line = CatalogAnnounce::tripLine('Сказочный Край', 'Владимир', '2026-10-12', '09:00', 3);
        $this->assertStringContainsString('Сказочный Край → Владимир', $line);
        $this->assertStringContainsString('09:00', $line);
        $this->assertStringContainsString('мест: 3', $line);
    }

    public function test_trip_line_without_time(): void
    {
        $line = CatalogAnnounce::tripLine('Сказочный Край', 'Владимир', '2026-10-12', '', 1);
        $this->assertStringNotContainsString(', ,', $line);
        $this->assertStringEndsWith('мест: 1', $line);
    }

    public function test_text_has_head_card_and_link(): void
    {
        $text = CatalogAnnounce::text('🔧 Новый инструмент в общей копилке', '«Перфоратор» · Электроинструмент', 'https://example.test/x');
        $this->assertStringContainsString('🔧 Новый инструмент в общей копилке', $text);
        $this->assertStringContainsString('«Перфоратор» · Электроинструмент', $text);
        $this->assertStringContainsString('Открыть: https://example.test/x', $text);
        $this->assertLessThanOrEqual(6, substr_count($text, "\n"));   // сообщение остаётся коротким
    }

    public function test_delivery_line_has_kind_place_and_date_but_not_what(): void
    {
        $line = CatalogAnnounce::deliveryLine('buy', 'Аптека, Северская', '2026-09-28');
        $this->assertStringStartsWith('Купить · Аптека, Северская', $line);
        $this->assertStringContainsString('к 28 сентября 2026', $line);
    }

    public function test_delivery_line_without_date(): void
    {
        $this->assertSame('Забрать · СДЭК', CatalogAnnounce::deliveryLine('pickup', 'СДЭК', null));
    }

    public function test_delivery_line_collapses_newlines_in_place(): void
    {
        $line = CatalogAnnounce::deliveryLine('pickup', "СДЭК\n.\nСеверская", null);
        $this->assertSame('Забрать · СДЭК . Северская', $line);
        $this->assertStringNotContainsString("\n", $line);
    }
}
