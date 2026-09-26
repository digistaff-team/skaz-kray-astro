<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Раздел «Доставка» должен появиться на проде выключенным: Sections::isEnabled
 * считает неизвестный ключ включённым, поэтому выключает его сама миграция.
 */
final class DeliveriesMigrationTest extends TestCase
{
    private function sql(): string
    {
        return (string) file_get_contents(__DIR__ . '/../config/deliveries.sql');
    }

    public function test_migration_creates_table(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS deliveries', $this->sql());
    }

    public function test_migration_disables_section(): void
    {
        $this->assertMatchesRegularExpression(
            "/INSERT\s+IGNORE\s+INTO\s+app_sections\s*\(section_key,\s*enabled\)\s*VALUES\s*\('dostavka',\s*0\)/i",
            $this->sql()
        );
    }

    public function test_sqlite_schema_has_table(): void
    {
        $pdo = make_test_db();
        $cols = array_column($pdo->query('PRAGMA table_info(deliveries)')->fetchAll(), 'name');
        foreach (['requester_id', 'carrier_id', 'trip_id', 'kind', 'what', 'place', 'need_by', 'budget',
                  'pickup_code', 'note', 'receipt_sum', 'status', 'created_at', 'accepted_at', 'delivered_at', 'settled_at'] as $c) {
            $this->assertContains($c, $cols, "нет колонки {$c}");
        }
    }
}
