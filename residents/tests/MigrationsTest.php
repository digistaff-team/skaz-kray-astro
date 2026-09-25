<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Migrations;

final class MigrationsTest extends TestCase
{
    public function test_every_sql_file_is_listed_once(): void
    {
        $dir = __DIR__ . '/../config';
        $manifest = Migrations::manifest((string) file_get_contents($dir . '/migrations.txt'));
        $files = array_map('basename', glob($dir . '/*.sql') ?: []);
        [$unlisted, $missing, $dups] = Migrations::problems($manifest, $files);
        $this->assertSame([], $unlisted, 'Новый .sql — допишите его в конец config/migrations.txt');
        $this->assertSame([], $missing, 'В config/migrations.txt есть файл, которого нет в config/');
        $this->assertSame([], $dups);
    }

    public function test_manifest_skips_comments_and_blank_lines(): void
    {
        $this->assertSame(['a.sql', 'b.sql'], Migrations::manifest("# заголовок\n\na.sql\r\n  b.sql  \n# хвост\n"));
    }

    public function test_pending_keeps_manifest_order(): void
    {
        $this->assertSame(['b.sql', 'd.sql'], Migrations::pending(['a.sql', 'b.sql', 'c.sql', 'd.sql'], ['c.sql', 'a.sql']));
        $this->assertSame([], Migrations::pending(['a.sql'], ['a.sql', 'old.sql']));
    }

    public function test_up_to_includes_given_file(): void
    {
        $this->assertSame(['a.sql', 'b.sql'], Migrations::upTo(['a.sql', 'b.sql', 'c.sql'], 'b.sql'));
        $this->expectException(\InvalidArgumentException::class);
        Migrations::upTo(['a.sql'], 'x.sql');
    }
}
