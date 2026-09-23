<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\ResidentsRepository;

/**
 * Рендер справочника «Наши соседи»: страница целиком и фрагмент одной поляны.
 *
 * Разметка поместий живёт в общем партиале и рисуется из двух мест — со
 * страницей и в ответ на клик по поляне (ленивый режим MAX). Тест сторожит
 * именно это: оба пути должны давать одни и те же карточки, а переменные,
 * которые страница раньше держала у себя в области видимости, не должны
 * «теряться» в отдельно вызванном партиале.
 */
final class ResidentsDirectoryViewTest extends TestCase
{
    private ResidentsRepository $repo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $pdo = make_test_db();
        $pdo->exec("INSERT INTO families (id, email, telegram_id, telegram_username, password_hash, name, status) VALUES
            (1, 'rudenko@example.com', 111, 'sergey_tg', 'x', 'Сергей Руденко', 'active')");
        $pdo->exec("INSERT INTO households (id, glade, plot, estate_name, family_id, sort) VALUES
            (1, '(1) Обережная', '1', 'АгудариЯ', 1, 0),
            (2, '(4) Рассветная', '4', 'Солнышко', NULL, 1)");
        $pdo->exec("INSERT INTO household_owners (household_id, family_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO residents (household_id, full_name, skills, hometown, vk, phone, sort) VALUES
            (1, 'Руденко Сергей', 'компьютерный дизайн', 'Краснодар', 'vk.com/rudenko', '+7 900 000-00-00', 0),
            (2, 'Вишняков Андрей', 'пчеловодство', 'Москва', '', '', 0)");
        $this->repo = new ResidentsRepository();
    }

    /** @param array<string,mixed> $vars */
    private function render(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/../src/templates/residents/' . $template . '.php';
        return (string) ob_get_clean();
    }

    private function glades(bool $lazy): array
    {
        $glades = $this->repo->gladeGroups();
        foreach ($glades as &$g) {
            $g['count'] = count($g['hhs']);
            if ($lazy) { $g['hhs'] = []; }
        }
        unset($g);
        return $glades;
    }

    public function test_page_renders_households_inline(): void
    {
        $html = $this->render('directory', ['glades' => $this->glades(false), 'stats' => [], 'q' => '', 'lazy' => false]);
        $this->assertStringContainsString('Поляна Обережная (1)', $html);
        $this->assertStringContainsString('Руденко Сергей', $html);
        $this->assertStringContainsString('1 житель', $html);          // склонение из helpers.php
        $this->assertStringContainsString('https://vk.com/rudenko', $html);
        $this->assertStringNotContainsString('data-glade="', $html);   // подгружать нечего (в скрипте имя атрибута есть, в разметке — нет)
    }

    public function test_lazy_page_ships_headings_without_people(): void
    {
        $html = $this->render('directory', ['glades' => $this->glades(true), 'stats' => [], 'q' => '', 'lazy' => true]);
        $this->assertStringContainsString('Поляна Обережная (1)', $html);
        $this->assertStringContainsString('1 участок', $html);          // счётчик остаётся
        $this->assertStringContainsString('data-glade="(1) обережная"', $html);
        // Главное: ПДн жителей в такую страницу не попадают вовсе.
        $this->assertStringNotContainsString('Руденко Сергей', $html);
        $this->assertStringNotContainsString('+7 900 000-00-00', $html);
    }

    public function test_glade_fragment_matches_what_the_page_renders(): void
    {
        $group = $this->repo->gladeGroup(ResidentsRepository::gladeKey('(1) Обережная'));
        $this->assertNotNull($group);
        $fragment = $this->render('_glade_body', ['hhs' => $group['hhs'], 'gnum' => $group['num'], 'open' => false]);

        $this->assertStringContainsString('Руденко Сергей', $fragment);
        $this->assertStringContainsString('компьютерный дизайн', $fragment);
        $this->assertStringContainsString('участок 1-1', $fragment);
        $this->assertStringContainsString('1 житель', $fragment);
        // Приезжает свёрнутым — раскрывает житель, как и на обычной странице.
        $this->assertStringNotContainsString('res-hh--open', $fragment);

        // Ровно те же карточки, что страница рисует у себя.
        $page = $this->render('directory', ['glades' => $this->glades(false), 'stats' => [], 'q' => '', 'lazy' => false]);
        $this->assertStringContainsString(trim($fragment), $page);
    }

    public function test_unclaimed_household_stays_a_plain_plate(): void
    {
        $group = $this->repo->gladeGroup(ResidentsRepository::gladeKey('(4) Рассветная'));
        $fragment = $this->render('_glade_body', ['hhs' => $group['hhs'], 'gnum' => $group['num'], 'open' => false]);
        // Поместье не привязано к аккаунту — ПДн не раскрываем и через фрагмент.
        $this->assertStringContainsString('res-hh--free', $fragment);
        $this->assertStringNotContainsString('Вишняков Андрей', $fragment);
    }
}
