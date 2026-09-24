<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Sections;
use SkazResidents\Repository\SectionSettingsRepository;

/**
 * Меню в шапке раздела жителей.
 *
 * Пункт «Ярмарка» раньше всегда вёл на публичную /yarmarka/ — а там только товары
 * «на сайте», без объявлений «только соседям». Вошедший житель через меню видел
 * урезанную ярмарку в оформлении внешнего сайта.
 */
final class LayoutMenuTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        make_test_db();
        Sections::reset();
    }

    private function render(): string
    {
        $title = 'Проверка';
        $content = '';
        ob_start();
        require __DIR__ . '/../src/templates/layout.php';
        return (string) ob_get_clean();
    }

    /** @return array<int,string> адреса пунктов меню */
    private function menu(string $html): array
    {
        $nav = substr($html, (int) strpos($html, '<nav class="res-nav">'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
        preg_match_all('~<a href="([^"]+)">~', $nav, $m);
        return $m[1];
    }

    public function test_resident_gets_internal_market(): void
    {
        $_SESSION['family_id'] = 1;
        $menu = $this->menu($this->render());
        $this->assertContains('/poselenie/yarmarka', $menu);
        $this->assertNotContains('/yarmarka/', $menu);
    }

    public function test_guest_gets_public_market(): void
    {
        $menu = $this->menu($this->render());
        $this->assertContains('/yarmarka/', $menu);
        $this->assertNotContains('/poselenie/yarmarka', $menu);
    }

    public function test_disabled_market_disappears_for_resident(): void
    {
        (new SectionSettingsRepository())->setEnabled('yarmarka', false, '2026-09-24 10:00:00');
        Sections::reset();
        $_SESSION['family_id'] = 1;
        $menu = $this->menu($this->render());
        $this->assertNotContains('/poselenie/yarmarka', $menu);
        $this->assertNotContains('/yarmarka/', $menu);
    }
}
