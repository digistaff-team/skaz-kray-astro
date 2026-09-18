<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\WaterLevelRepository;
use SkazResidents\Service\WaterLevelImport;

/**
 * Заливка прошлых замеров: разбор архивного формата, отсев битых записей,
 * безопасность повторного запуска и честный отчёт.
 */
final class WaterLevelImportTest extends TestCase
{
    private \PDO $pdo;
    private WaterLevelRepository $history;
    private WaterLevelImport $import;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        $this->history = new WaterLevelRepository();
        $this->import = new WaterLevelImport($this->history);
    }

    /** @return array<string,mixed> */
    private function record(string $at, float $level, float $change = 0.0): array
    {
        return ['created_at' => $at, 'water_level' => $level, 'change_24h' => $change];
    }

    public function test_imports_records_rounded_to_hours(): void
    {
        $res = $this->import->import([
            $this->record('2026-05-31T14:29:25.100Z', -93.62),
            $this->record('2026-06-01T08:10:00.000Z', -120.0, -26.0),
        ]);

        $this->assertSame(2, $res['added']);
        $this->assertSame(0, $res['skipped']);
        $points = $this->history->dailyPoints(3650, '2026-06-02 00:00:00');
        $this->assertSame(['2026-05-31', '2026-06-01'], array_column($points, 'date'));
        $this->assertSame(-93.62, $points[0]['level_cm']);
    }

    public function test_second_run_overwrites_instead_of_duplicating(): void
    {
        $this->import->import([$this->record('2026-05-31T14:29:25.100Z', -93.62)]);
        $res = $this->import->import([$this->record('2026-05-31T14:55:00.000Z', -95.0)]);

        $this->assertSame(0, $res['added']);
        $this->assertSame(1, $res['updated'], 'тот же час — перезапись, а не дубль');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM water_level_history')->fetchColumn());
    }

    public function test_zeroes_out_implausible_daily_change(): void
    {
        // В архиве проекта лежит change_24h = 888 — артефакт прежнего LLM-парсера.
        $res = $this->import->import([$this->record('2026-05-31T14:29:25.100Z', -93.62, 888.0)]);

        $this->assertSame(1, $res['added']);
        $this->assertSame(0.0, (float) $this->history->latest()['change_24h']);
        $this->assertStringContainsString('обнулено', implode(' ', $res['notes']));
    }

    public function test_skips_levels_outside_gauge_range(): void
    {
        $res = $this->import->import([$this->record('2026-05-31T14:00:00Z', 99999.0)]);

        $this->assertSame(0, $res['added']);
        $this->assertSame(1, $res['skipped']);
        $this->assertStringContainsString('вне границ поста', implode(' ', $res['notes']));
    }

    public function test_skips_broken_records(): void
    {
        $res = $this->import->import([
            ['water_level' => -100.0],                      // без даты
            ['created_at' => 'не дата', 'water_level' => 1], // дата не читается
            $this->record('2026-05-31T14:00:00Z', 0),        // а этот в порядке
            ['created_at' => '2026-05-31T15:00:00Z'],        // без уровня
        ]);

        $this->assertSame(1, $res['added']);
        $this->assertSame(3, $res['skipped']);
        $this->assertCount(3, $res['notes']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $res = $this->import->import([$this->record('2026-05-31T14:00:00Z', -93.62)], true);

        $this->assertSame(1, $res['added'], 'отчёт считает, что было бы добавлено');
        $this->assertNull($this->history->latest(), 'но в историю ничего не легло');
    }

    public function test_load_reads_local_file(): void
    {
        $path = sys_get_temp_dir() . '/water-import-test.json';
        file_put_contents($path, json_encode([$this->record('2026-05-31T14:00:00Z', -93.62)]));

        $records = $this->import->load($path);
        unlink($path);

        $this->assertIsArray($records);
        $this->assertCount(1, $records);
    }

    public function test_load_returns_null_on_garbage(): void
    {
        $path = sys_get_temp_dir() . '/water-import-garbage.json';
        file_put_contents($path, 'не json');

        $this->assertNull($this->import->load($path));
        unlink($path);
    }
}
