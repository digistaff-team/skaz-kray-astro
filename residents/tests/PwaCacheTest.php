<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Controller\{AppController, PwaController};

/**
 * Кеш приложения (service worker) не должен хранить ПДн: страницы и
 * загруженные фото — только из сети, в кеше лишь оформление и заглушка
 * «Нет сети». Тест читает текст sw.js: логика живёт в браузере, и здесь
 * её можно проверить только по тому, что сервер отдаёт.
 */
final class PwaCacheTest extends TestCase
{
    private function sw(): string
    {
        ob_start();
        (new PwaController())->serviceWorker();
        return (string) ob_get_clean();
    }

    public function test_pages_are_not_precached(): void
    {
        $sw = $this->sw();
        $this->assertStringNotContainsString("'/poselenie/app'", $sw);   // личная главная
        $this->assertStringContainsString("'/poselenie/offline'", $sw);
    }

    public function test_navigation_goes_to_network_without_storing(): void
    {
        $sw = $this->sw();
        $this->assertStringContainsString(
            "e.respondWith(fetch(req).catch(() => caches.match('/poselenie/offline')));", $sw
        );
        // Кладём в кеш ровно в одном месте — в ветке оформления.
        $this->assertSame(1, substr_count($sw, '.put(req'));
        $this->assertStringContainsString("url.pathname.startsWith('/poselenie/assets/')", $sw);
    }

    public function test_cache_version_moved_past_the_one_with_pages(): void
    {
        // v4 хранила страницы с ПДн; activate стирает все версии, кроме текущей.
        $this->assertStringNotContainsString("'skazapp-v4'", $this->sw());
    }

    public function test_offline_page_renders_without_login(): void
    {
        $_SESSION = [];
        ob_start();
        (new AppController())->offline();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Нет сети', $html);
    }
}
