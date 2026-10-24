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

    /** @param array<int,array<string,mixed>> $tasks */
    private function render(array $tasks = []): string
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

    public function test_tasks_block_shows_count_and_first_two_titles(): void
    {
        $task = static fn(string $title): array => ['kind' => 'tool_request', 'title' => $title, 'detail' => '',
            'link' => '/poselenie/instrumenty/moi', 'date' => '', 'urgent' => false];
        $html = $this->render([$task('Заявка на «Дрель»'), $task('Заявка на «Пилу»'), $task('Заявка на «Лопату»')]);

        $this->assertStringContainsString('<a class="app-tasks" href="/poselenie/dela">', $html);
        $this->assertStringContainsString('Ждут вас: 3 дела', $html);
        $this->assertStringContainsString('Заявка на «Дрель» · Заявка на «Пилу»', $html);
        $this->assertStringNotContainsString('Лопату', $html);
        // Блок стоит первым — над дневником.
        $this->assertLessThan(strpos($html, 'class="app-diary"'), strpos($html, 'class="app-tasks"'));
    }

    public function test_no_tasks_no_block(): void
    {
        $this->assertStringNotContainsString('app-tasks', $this->render([]));
    }

    public function test_tasks_block_escapes_titles(): void
    {
        $html = $this->render([['kind' => 'tool_request', 'title' => 'Заявка на «<b>x</b>»', 'detail' => '',
            'link' => '/poselenie/instrumenty/moi', 'date' => '', 'urgent' => false]]);
        $this->assertStringContainsString('Ждут вас: 1 дело', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    /** Посев именинника: поместье привязано к семье из setUp (id=1), если familyId не задан. */
    private function seedBirthday(string $name, string $birthDate, ?int $familyId = 1): void
    {
        $pdo = Database::pdo();
        if (!$pdo->query('SELECT COUNT(*) FROM households WHERE id = 1')->fetchColumn()) {
            $family = $familyId === null ? 'NULL' : (string) $familyId;
            $pdo->exec("INSERT INTO households (id, glade, plot, estate_name, family_id, sort)
                        VALUES (1, '(1) Обережная', '1', 'АгудариЯ', $family, 0)");
        }
        $st = $pdo->prepare('INSERT INTO residents (household_id, full_name, birth_date, sort) VALUES (1, ?, ?, 0)');
        $st->execute([$name, $birthDate]);
    }

    public function test_birthday_banner_shows_todays_celebrant(): void
    {
        $this->seedBirthday('Крылов Владислав', date('Y-m-d'));
        $html = $this->render();

        $this->assertStringContainsString('<div class="app-birthday">', $html);
        $this->assertStringContainsString('🎉 С Днём Рождения, Крылов Владислав!', $html);
        // Баннер — первым блоком: и выше «Мои дела», и выше дневника.
        $this->assertLessThan(strpos($html, 'class="app-diary"'), strpos($html, 'class="app-birthday"'));
    }

    public function test_no_birthday_today_no_banner(): void
    {
        // Дата есть, но не сегодняшняя (вчера не совпадёт с сегодня ни при каком календаре).
        $this->seedBirthday('Крылов Владислав', date('Y-m-d', strtotime('-1 day')));
        $this->assertStringNotContainsString('app-birthday', $this->render());
    }

    public function test_unclaimed_household_keeps_the_name_hidden(): void
    {
        // ПДн непривязанного поместья не раскрываем — даже ради дня рождения.
        $this->seedBirthday('Крылов Владислав', date('Y-m-d'), null);
        $html = $this->render();
        $this->assertStringNotContainsString('app-birthday', $html);
        $this->assertStringNotContainsString('Крылов', $html);
    }

    public function test_banner_lists_all_todays_celebrants(): void
    {
        $this->seedBirthday('Крылов Владислав', date('Y-m-d'));
        $this->seedBirthday('Мельникова Ася', date('Y-m-d'));
        $this->assertStringContainsString('🎉 С Днём Рождения, Крылов Владислав и Мельникова Ася!', $this->render());
    }

    public function test_banner_escapes_names(): void
    {
        $this->seedBirthday('Крылов <b>Владислав</b>', date('Y-m-d'));
        $html = $this->render();
        $this->assertStringNotContainsString('Крылов <b>', $html);
        $this->assertStringContainsString('Крылов &lt;b&gt;Владислав&lt;/b&gt;', $html);
    }
}
