<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Sections;
use SkazResidents\Service\MyTasks;
use SkazResidents\Repository\{SectionSettingsRepository, FamilyRepository, ToolRepository, ToolLoanRepository};

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
        MyTasks::reset();   // дела запоминаются на запрос — между тестами их надо забывать
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

    public function test_resident_menu_shows_tasks_item_and_count_only_when_there_are_tasks(): void
    {
        $fam = new FamilyRepository();
        $me = $fam->createPending('me@skaz-kray.ru', 'H', 'Я');
        $nei = $fam->createPending('nei@skaz-kray.ru', 'H', 'Сосед');
        $_SESSION['family_id'] = $me;

        // Дел нет — пункт есть, числа нет.
        $html = $this->render();
        $this->assertContains('/poselenie/dela', $this->menu($html));
        $this->assertStringNotContainsString('res-nav-count', $html);

        // Появилась заявка — число показано; заодно видно, что пустота выше была не из-за сбоя.
        $tool = (new ToolRepository())->create($me, 'Дрель', 'x', null, null, null, '2026-09-20 10:00:00');
        (new ToolLoanRepository())->create($tool, $nei, null, null, '2026-09-20 10:00:00');
        MyTasks::reset();
        $this->assertStringContainsString('Мои дела <span class="res-nav-count">1</span>', $this->render());
    }

    public function test_guest_has_no_tasks_item(): void
    {
        $this->assertNotContains('/poselenie/dela', $this->menu($this->render()));
    }
}
