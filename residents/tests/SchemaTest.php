<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function test_sqlite_schema_creates_all_tables(): void
    {
        $pdo = make_test_db();
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(
            ['diary_entries', 'families', 'images', 'login_attempts', 'password_resets', 'products'],
            $names
        );
    }
}
