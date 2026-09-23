<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Database, Sections};
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\SectionSettingsRepository;

/**
 * Раскладка плиток на главной приложения.
 *
 * Порядок в разметке — это значение по умолчанию: житель волен переставить
 * плитки длинным нажатием, и тогда его порядок живёт в localStorage. Тест
 * сторожит именно умолчание, потому что вручную в длинной строке разметки его
 * легко сбить, а увидеть это можно только глазами в приложении.
 */
final class AppHomeTilesTest extends TestCase
{
    private const EXPECTED = [
        '/poselenie/sosedi',        '/poselenie/moye-pomestie',
        '/poselenie/knigi',         '/poselenie/instrumenty',
        '/poselenie/obshchiy-dom',  '/poselenie/yarmarka',
        '/poselenie/zakupki',       '/poselenie/poezdki',
    ];

    protected function setUp(): void
    {
        $_SESSION = [];
        make_test_db();
        Sections::reset();
        Database::pdo()->exec(
            "INSERT INTO families (id, email, password_hash, name, status) VALUES (1, 'a@example.com', 'x', 'Семья', 'active')"
        );
    }

    private function render(): string
    {
        $dash = (new AppDashboard())->build(1);
        $me = 'Поместье «АгудариЯ»';
        $savedAt = '10:00';
        ob_start();
        require __DIR__ . '/../src/templates/app/home.php';
        return (string) ob_get_clean();
    }

    /** @return array<int,string> адреса плиток в порядке разметки */
    private function tiles(string $html): array
    {
        $grid = substr($html, (int) strpos($html, '<div class="app-grid"'));
        $grid = substr($grid, 0, (int) strpos($grid, '</div>'));
        preg_match_all('~class="app-tile" href="([^"]+)"~', $grid, $m);
        return $m[1];
    }

    public function test_default_tile_order(): void
    {
        $this->assertSame(self::EXPECTED, $this->tiles($this->render()));
    }

    public function test_static_captions_on_tiles(): void
    {
        // Подпись постоянная, а не счётчик: «на полке 0» и «свободно 0» на пустом
        // разделе выглядели так, будто он не работает.
        $html = $this->render();
        $this->assertStringContainsString('<b>Книги</b><span>библиотека поселения</span>', $html);
        $this->assertStringContainsString('<b>Инструменты</b><span>арсенал поселения</span>', $html);
        $this->assertStringContainsString('<b>Поездки</b><span>попутчики и доставка</span>', $html);
        $this->assertStringNotContainsString('на полке', $html);
        $this->assertStringNotContainsString('свободно', $html);
    }

    public function test_diaries_have_no_tile_but_keep_their_block(): void
    {
        $html = $this->render();
        // Плитки у дневников нет — на раздел ведёт широкий блок над сеткой.
        $this->assertNotContains('/poselenie/dnevniki', $this->tiles($html));
        $this->assertStringContainsString('app-diary', $html);
    }

    public function test_disabled_section_drops_its_tile_and_keeps_the_rest_in_order(): void
    {
        (new SectionSettingsRepository())->setEnabled('knigi', false, '2026-09-23 10:00:00');
        Sections::reset();
        $tiles = $this->tiles($this->render());
        $this->assertNotContains('/poselenie/knigi', $tiles);
        $this->assertSame(
            array_values(array_diff(self::EXPECTED, ['/poselenie/knigi'])),
            $tiles
        );
    }

    public function test_disabled_diaries_hide_the_block_too(): void
    {
        // Плитки у дневников нет, значит блок над сеткой — единственная ссылка
        // на раздел, и выключатель обязан гасить и его.
        (new SectionSettingsRepository())->setEnabled('dnevniki', false, '2026-09-23 10:00:00');
        Sections::reset();
        $this->assertStringNotContainsString('app-diary', $this->render());
    }
}
