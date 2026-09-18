<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Config;
use SkazResidents\Repository\WaterLevelRepository;
use SkazResidents\Service\WaterLevel;

/**
 * Уровень воды в Шебше: пересчёт в БСВ, запас до моста, подписи и геометрия
 * спарклайна. Сеть не дёргается: в историю кладём свежий замер, поэтому panel()
 * считает данные актуальными и к источнику не идёт.
 */
final class WaterLevelTest extends TestCase
{
    private \PDO $pdo;
    private WaterLevel $svc;
    private WaterLevelRepository $repo;

    protected function setUp(): void
    {
        Config::set([]);
        $this->pdo = make_test_db();
        $this->repo = new WaterLevelRepository();
        $this->svc = new WaterLevel($this->repo);
    }

    /** @return array<int,array{date:string,level_cm:float,change_24h:float}> */
    private function points(float ...$levels): array
    {
        $out = [];
        foreach ($levels as $i => $cm) {
            $out[] = ['date' => sprintf('2026-09-%02d', $i + 1), 'level_cm' => $cm, 'change_24h' => 0.0];
        }
        return $out;
    }

    public function test_bsv_converts_centimetres_above_gauge_zero(): void
    {
        $this->assertSame(38.158, $this->svc->bsv(0.0), 'ноль поста и есть ноль поста');
        $this->assertSame(35.158, round($this->svc->bsv(-300.0), 3), 'кромка моста ≈ −300 см от нуля');
    }

    public function test_bridge_gap_positive_when_water_below_bridge(): void
    {
        $this->assertSame(749.0, $this->svc->bridgeGapCm(-1049.0), 'вода на 7,49 м ниже кромки');
        $this->assertSame(-50.0, $this->svc->bridgeGapCm(-250.0), 'вода выше кромки — запас отрицательный');
    }

    public function test_status_thresholds(): void
    {
        $this->assertSame('calm',  $this->svc->status(749.0));
        $this->assertSame('calm',  $this->svc->status(300.0), 'ровно 3 м до кромки — ещё спокойно');
        $this->assertSame('watch', $this->svc->status(299.0), 'ближе 3 м — предупреждаем');
        $this->assertSame('watch', $this->svc->status(120.0));
        $this->assertSame('alert', $this->svc->status(100.0), 'метр до кромки — тревога');
        $this->assertSame('alert', $this->svc->status(40.0));
        $this->assertSame('alert', $this->svc->status(-10.0), 'мост под водой — тревога');
    }

    public function test_distance_switches_between_metres_and_centimetres(): void
    {
        $this->assertSame('7,5 м', $this->svc->formatDistance(749.0));
        $this->assertSame('40 см', $this->svc->formatDistance(40.0));
    }

    public function test_change_label_keeps_direction(): void
    {
        $this->assertSame('за сутки поднялась на 12 см', $this->svc->changeLabel(12.0));
        $this->assertSame('за сутки опустилась на 8 см', $this->svc->changeLabel(-8.0));
        $this->assertSame('за сутки без изменений', $this->svc->changeLabel(0.0));
    }

    public function test_chart_needs_at_least_two_points(): void
    {
        $this->assertNull($this->svc->chart([]));
        $this->assertNull($this->svc->chart($this->points(-1000.0)));
    }

    public function test_chart_spans_full_width_and_height(): void
    {
        $c = $this->svc->chart($this->points(-1000.0, -950.0, -900.0), 300, 110);
        $this->assertNotNull($c);
        $coords = array_map(
            static fn(string $pair): array => array_map('floatval', explode(',', $pair)),
            explode(' ', $c['poly'])
        );
        $this->assertCount(3, $coords);
        $this->assertSame(0.0, $coords[0][0], 'первая точка — у левого края');
        $this->assertSame(300.0, $coords[2][0], 'последняя — у правого');
        $this->assertGreaterThan($coords[2][1], $coords[0][1], 'низкая вода рисуется ниже высокой');
        $this->assertSame(3, $c['days']);
    }

    public function test_chart_hides_marks_outside_data_range(): void
    {
        // Вода много ниже моста (−300 см) — линия отметки ушла бы за рамку.
        $far = $this->svc->chart($this->points(-1050.0, -1040.0));
        $this->assertSame([], $far['marks']);

        // Вода подошла к мосту — отметка попадает в диапазон и рисуется.
        $near = $this->svc->chart($this->points(-350.0, -250.0));
        $this->assertCount(1, $near['marks']);
        $this->assertSame('кромка моста', $near['marks'][0]['label']);
    }

    public function test_chart_survives_flat_history(): void
    {
        $c = $this->svc->chart($this->points(-1000.0, -1000.0, -1000.0));
        $this->assertNotNull($c, 'без размаха диаграмма всё равно рисуется');
        foreach (explode(' ', $c['poly']) as $pair) {
            $this->assertSame(55.0, (float) explode(',', $pair)[1], 'штиль — ровная линия по центру');
        }
    }

    public function test_panel_uses_fresh_record_without_network(): void
    {
        $now = '2026-09-18 12:00:00';
        $this->repo->save($now, -1049.0, -8.0);

        $panel = $this->svc->panel($now);

        $this->assertSame(-1049.0, $panel['level']);
        $this->assertSame('7,5 м', $panel['gapLabel']);
        $this->assertFalse($panel['flooded']);
        $this->assertSame('calm', $panel['status']);
        $this->assertSame('за сутки опустилась на 8 см', $panel['changeLabel']);
        $this->assertNull($panel['chart'], 'по одной точке линию не построить');
    }

    public function test_panel_builds_chart_from_history(): void
    {
        foreach (['2026-09-15 10:00:00' => -1100.0, '2026-09-16 10:00:00' => -1080.0, '2026-09-17 10:00:00' => -1060.0] as $at => $cm) {
            $this->repo->save($at, $cm, 0.0);
        }
        $now = '2026-09-17 10:30:00';
        $this->repo->save($now, -1049.0, 11.0);

        $panel = $this->svc->panel($now);

        $this->assertNotNull($panel['chart']);
        $this->assertSame(3, $panel['chart']['days'], 'по одной точке за сутки');
    }

    public function test_panel_reports_flooded_bridge(): void
    {
        $now = '2026-09-18 12:00:00';
        $this->repo->save($now, -280.0, 40.0);   // вода на 20 см выше кромки

        $panel = $this->svc->panel($now);

        $this->assertTrue($panel['flooded']);
        $this->assertSame('alert', $panel['status']);
        $this->assertSame('20 см', $panel['gapLabel']);
    }
}
