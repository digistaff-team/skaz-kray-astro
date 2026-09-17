<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Sections;

/**
 * Выключенный раздел должен быть закрыт и по прямой ссылке, а не только спрятан
 * из меню. Гард ставится в контроллере (requireSection / requireHousehold), и
 * забыть его при добавлении раздела легко — тест сверяет список разделов с
 * исходниками контроллеров.
 */
final class SectionGuardsTest extends TestCase
{
    public function test_every_section_is_guarded_in_some_controller(): void
    {
        $code = '';
        foreach (glob(__DIR__ . '/../src/Controller/*.php') as $file) {
            $code .= file_get_contents($file);
        }

        foreach (array_keys(Sections::LIST) as $key) {
            $guarded = str_contains($code, "requireSection('{$key}')")
                || str_contains($code, "requireHousehold('{$key}')");
            $this->assertTrue($guarded, "Раздел «{$key}» не закрыт гардом ни в одном контроллере");
        }
    }

    public function test_guards_use_known_section_keys(): void
    {
        $code = '';
        foreach (glob(__DIR__ . '/../src/Controller/*.php') as $file) {
            $code .= file_get_contents($file);
        }
        preg_match_all("/require(?:Section|Household)\('([a-z_]+)'\)/", $code, $m);

        foreach (array_unique($m[1]) as $used) {
            $this->assertArrayHasKey($used, Sections::LIST, "Гард ссылается на неизвестный ключ раздела «{$used}»");
        }
    }
}
