<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Upload;

/**
 * Сжатие снимков на телефоне (partials/photo-shrink.php) — единственное, что
 * удерживает фото с камеры в пределах upload_max_filesize на сервере. Тест следит,
 * что скрипт подключён в обоих лейаутах и не разъехался с сервером по размеру кадра.
 */
final class PhotoShrinkTest extends TestCase
{
    private const DIR = __DIR__ . '/../src/templates';

    public function test_both_layouts_load_shrink_before_submit_guard(): void
    {
        $layouts = [
            'раздел жителей' => self::DIR . '/layout.php',
            'Совет'          => self::DIR . '/council/layout.php',
        ];
        foreach ($layouts as $name => $file) {
            $code = (string) file_get_contents($file);
            $shrink = strpos($code, 'partials/photo-shrink.php');
            $guard  = strpos($code, 'partials/submit-guard.php');

            $this->assertNotFalse($shrink, "$name: лейаут не подключает сжатие фото");
            $this->assertNotFalse($guard, "$name: лейаут не подключает защиту от повторной отправки");
            // Порядок важен: оба слушают submit на document, и сжатие должно
            // перехватить отправку первым, иначе форма уйдёт с неужатыми снимками.
            $this->assertLessThan($guard, $shrink, "$name: сжатие фото должно подключаться раньше submit-guard");
        }
    }

    public function test_client_and_server_agree_on_max_dimension(): void
    {
        $js = (string) file_get_contents(self::DIR . '/partials/photo-shrink.php');
        $this->assertSame(1, preg_match('/MAX_DIM\s*=\s*(\d+)/', $js, $m), 'в скрипте не найден MAX_DIM');

        $serverMax = (new \ReflectionClass(Upload::class))->getConstant('MAX_DIM');
        $this->assertSame($serverMax, (int) $m[1], 'клиент и Upload::saveImage ужимают до разного размера');
    }
}
