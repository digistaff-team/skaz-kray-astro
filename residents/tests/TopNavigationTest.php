<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Навигация вверху страницы: с любого экрана портала можно уйти либо назад,
 * либо сразу на главную.
 *
 * Тест структурный, а не про внешний вид: он следит, чтобы новая страница не
 * появилась вовсе без верхней навигации (так уже случилось с поездками,
 * дневниками, модерацией и формами поместья) и чтобы ссылки не рисовали руками
 * в обход общего партиала — иначе подписи снова разъедутся.
 */
final class TopNavigationTest extends TestCase
{
    private const DIR = __DIR__ . '/../src/templates';

    /** Экраны, которым верхняя навигация не нужна, и почему. */
    private const EXEMPT = [
        'app/home.php'    => 'сама главная',
        'app/layout.php'  => 'обёртка главной, не страница',
        'app/offline.php' => 'заглушка без сети: уводить некуда',
        'layout.php'      => 'обёртка, не страница',
    ];

    /** Разделы, живущие вне портала или до входа в него. */
    private const EXEMPT_DIRS = [
        'auth/',          // вход, регистрация, восстановление пароля
        'council/',       // Совету навигацию даёт его layout — см. отдельный тест ниже
        'tg/',            // технические экраны запуска мини-приложения
        'max/',
        'partials/',
        'public/',        // внешний сайт
    ];

    /** @return array<int,string> пути шаблонов относительно templates/ */
    private function pageTemplates(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::DIR) + 1));
            if (str_starts_with(basename($rel), '_')) { continue; }   // партиалы кусков разметки
            foreach (self::EXEMPT_DIRS as $dir) {
                if (str_starts_with($rel, $dir)) { continue 2; }
            }
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    public function test_every_page_offers_back_or_home(): void
    {
        $missing = [];
        foreach ($this->pageTemplates() as $rel) {
            if (isset(self::EXEMPT[$rel])) { continue; }
            $src = (string) file_get_contents(self::DIR . '/' . $rel);
            if (!str_contains($src, "partials/back.php")) { $missing[] = $rel; }
        }
        $this->assertSame([], $missing, 'Эти страницы остались без навигации вверху');
    }

    public function test_navigation_is_never_hand_written(): void
    {
        $handmade = [];
        foreach ($this->pageTemplates() as $rel) {
            $src = (string) file_get_contents(self::DIR . '/' . $rel);
            if (str_contains($src, 'class="res-back')) { $handmade[] = $rel; }
        }
        $this->assertSame([], $handmade, 'Ссылки навигации рисуют мимо partials/back.php');
    }

    public function test_council_gets_navigation_from_its_layout(): void
    {
        // Страницы Совета своей навигации не рисуют — её ставит общий layout,
        // поэтому каталог council/ и выведен из проверки выше.
        $layout = (string) file_get_contents(self::DIR . '/council/layout.php');
        $this->assertStringContainsString('partials/back.php', $layout);
        $this->assertStringContainsString("'/sovet'", $layout);   // главная у Совета своя
    }

    public function test_partial_offers_both_links(): void
    {
        $src = (string) file_get_contents(self::DIR . '/partials/back.php');
        $this->assertStringContainsString('← Назад', $src);
        $this->assertStringContainsString('На главную', $src);
        // «Назад» снимается скриптом, когда возвращаться некуда — иначе подпись
        // обещала бы предыдущую страницу, а вела на запасной адрес.
        $this->assertStringContainsString('js-back-top', $src);
    }
}
