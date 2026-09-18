<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\WaterLevelRepository;

/**
 * История уровня воды: одна строка на час (повтор внутри часа обновляет её),
 * выборка последнего замера и суточных точек для диаграммы.
 */
final class WaterLevelRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private WaterLevelRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        $this->repo = new WaterLevelRepository();
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM water_level_history')->fetchColumn();
    }

    public function test_save_keeps_one_row_per_hour(): void
    {
        $this->repo->save('2026-09-18 12:05:00', -1050.0, -5.0);
        $this->repo->save('2026-09-18 12:47:31', -1049.0, -6.0);

        $this->assertSame(1, $this->rowCount(), 'второй замер в том же часу не плодит строку');
        $latest = $this->repo->latest();
        $this->assertSame('2026-09-18 12:00:00', $latest['measured_at'], 'минуты обнуляются');
        $this->assertSame(-1049.0, (float) $latest['level_cm'], 'строка обновлена свежим значением');
    }

    public function test_latest_returns_newest_hour(): void
    {
        $this->repo->save('2026-09-18 10:00:00', -1100.0, 0.0);
        $this->repo->save('2026-09-18 14:00:00', -1040.0, 60.0);
        $this->repo->save('2026-09-18 12:00:00', -1070.0, 30.0);

        $this->assertSame('2026-09-18 14:00:00', $this->repo->latest()['measured_at']);
    }

    public function test_latest_is_null_on_empty_history(): void
    {
        $this->assertNull($this->repo->latest());
    }

    public function test_daily_points_take_last_reading_of_each_day(): void
    {
        $this->repo->save('2026-09-16 08:00:00', -1100.0, 0.0);
        $this->repo->save('2026-09-16 20:00:00', -1090.0, 10.0);   // это и попадёт в точку за 16-е
        $this->repo->save('2026-09-17 09:00:00', -1080.0, 10.0);

        $points = $this->repo->dailyPoints(30, '2026-09-17 12:00:00');

        $this->assertSame(['2026-09-16', '2026-09-17'], array_column($points, 'date'), 'по дню и по возрастанию');
        $this->assertSame(-1090.0, $points[0]['level_cm']);
    }

    public function test_daily_points_ignore_records_older_than_window(): void
    {
        $this->repo->save('2026-07-01 10:00:00', -1200.0, 0.0);   // старше 30 суток
        $this->repo->save('2026-09-17 10:00:00', -1080.0, 0.0);

        $points = $this->repo->dailyPoints(30, '2026-09-17 12:00:00');

        $this->assertSame(['2026-09-17'], array_column($points, 'date'));
    }
}
