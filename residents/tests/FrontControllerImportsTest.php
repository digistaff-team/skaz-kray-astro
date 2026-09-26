<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * public/index.php — без namespace: класс, созданный через `new X()` без `use`,
 * ищется в глобальном пространстве и роняет КАЖДЫЙ запрос фатальной ошибкой.
 * Контроллерных тестов нет, так что забытый импорт иначе всплыл бы только на проде.
 */
final class FrontControllerImportsTest extends TestCase
{
    private string $code;

    protected function setUp(): void
    {
        $this->code = (string) file_get_contents(__DIR__ . '/../public/index.php');
    }

    /** @return array<string,string> короткое имя => полное имя класса */
    private function imports(): array
    {
        preg_match_all('/^use\s+([A-Za-z_\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $this->code, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $u) {
            $fq = $u[1];
            $parts = explode('\\', $fq);
            $short = ($u[2] ?? '') !== '' ? $u[2] : end($parts);
            $out[$short] = $fq;
        }
        return $out;
    }

    public function test_every_instantiated_class_is_imported_or_global(): void
    {
        preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][\w\\\\]*)\s*\(/', $this->code, $m);
        $imports = $this->imports();
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $name) {
            if (str_contains($name, '\\')) {
                $this->assertTrue(class_exists(ltrim($name, '\\')), "Класс {$name} не найден");
                continue;
            }
            // Без импорта допустим только встроенный глобальный класс (PDO, DateTime…) —
            // проверяем без автозагрузки: наши классы все в namespace SkazResidents.
            $this->assertTrue(isset($imports[$name]) || class_exists($name, false),
                "В public/index.php `new {$name}()` без `use …\\{$name};` — фатальная ошибка на каждом запросе");
        }
    }

    public function test_every_imported_class_exists(): void
    {
        $imports = $this->imports();
        $this->assertNotEmpty($imports);
        foreach ($imports as $short => $fq) {
            $this->assertTrue(class_exists($fq), "Импорт {$fq} ({$short}) указывает на несуществующий класс");
        }
    }
}
