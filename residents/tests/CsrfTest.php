<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Csrf;

final class CsrfTest extends TestCase
{
    private array $server = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->server = $_SERVER;
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = [];
        $_FILES = [];
    }

    public function test_token_is_stable_within_session(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a)); // 32 байта hex
    }

    public function test_check_accepts_valid_and_rejects_invalid(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::check($t));
        $this->assertFalse(Csrf::check('nope'));
        $this->assertFalse(Csrf::check(null));
    }

    /**
     * Фото тяжелее post_max_size: PHP выбрасывает тело целиком, поля и файлы пусты,
     * хотя браузер что-то посылал. Отличаем это от подделанной формы.
     */
    public function test_body_dropped_when_post_is_empty_but_something_was_sent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '12582912'; // 12 МБ снимков
        $this->assertTrue(Csrf::bodyDropped());
    }

    public function test_body_not_dropped_for_ordinary_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '120';
        $_POST = ['title' => 'Мёд'];
        $this->assertFalse(Csrf::bodyDropped());
    }

    public function test_body_not_dropped_when_only_files_arrived(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '120';
        $_FILES = ['photos' => ['name' => ['a.jpg'], 'error' => [0]]];
        $this->assertFalse(Csrf::bodyDropped());
    }

    public function test_body_not_dropped_without_request_body(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '0';
        $this->assertFalse(Csrf::bodyDropped());
    }

    public function test_body_not_dropped_on_get(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_LENGTH'] = '12582912';
        $this->assertFalse(Csrf::bodyDropped());
    }

    public function test_rejection_page_explains_heavy_files(): void
    {
        $page = Csrf::rejectionPage(true);
        $this->assertStringContainsString('Файлы слишком тяжёлые', $page);
        $this->assertStringContainsString('Вернуться назад', $page);
        $this->assertStringNotContainsString('токен', $page); // житель не виноват и слова такого не знает
    }

    public function test_rejection_page_for_stale_form(): void
    {
        $page = Csrf::rejectionPage(false);
        $this->assertStringContainsString('Форма устарела', $page);
        $this->assertStringContainsString('обновите страницу', $page);
    }

    public function test_shorthand_to_bytes(): void
    {
        $this->assertSame(8 * 1048576, Csrf::shorthandToBytes('8M'));
        $this->assertSame(512 * 1024, Csrf::shorthandToBytes('512K'));
        $this->assertSame(1073741824, Csrf::shorthandToBytes('1G'));
        $this->assertSame(1024, Csrf::shorthandToBytes('1024'));
        $this->assertSame(32 * 1048576, Csrf::shorthandToBytes(' 32m '));
    }

    public function test_shorthand_to_bytes_returns_null_when_unlimited_or_unparsable(): void
    {
        $this->assertNull(Csrf::shorthandToBytes(''));
        $this->assertNull(Csrf::shorthandToBytes('0'));
        $this->assertNull(Csrf::shorthandToBytes('-1'));
        $this->assertNull(Csrf::shorthandToBytes('ерунда'));
    }

    /** Ни один POST-обработчик не должен обрывать запрос голым текстом в обход guard(). */
    public function test_controllers_use_the_guard(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src/Controller'));
        $guards = 0;
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('Неверный токен формы', $code, $file->getFilename());
            $guards += substr_count($code, 'Csrf::guard();');
        }
        $this->assertGreaterThan(40, $guards);
    }
}
