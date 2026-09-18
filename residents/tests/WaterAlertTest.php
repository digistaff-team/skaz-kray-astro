<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Config;
use SkazResidents\Repository\{WaterAlertRepository, WaterLevelRepository};
use SkazResidents\Service\{WaterAlert, WaterLevel};

/**
 * Оповещение группы о подходе воды к мосту: пишем на ухудшении обстановки, на
 * отбое и по таймеру, пока держится тревога; молчим, когда ничего не изменилось.
 * Отправка подменена колбэком — ни Telegram, ни MAX не дёргаются.
 */
final class WaterAlertTest extends TestCase
{
    private \PDO $pdo;
    private WaterLevelRepository $history;
    private WaterAlertRepository $state;
    /** @var array<int,string> отправленные сообщения */
    private array $sent = [];

    protected function setUp(): void
    {
        Config::set([]);
        $this->pdo = make_test_db();
        $this->history = new WaterLevelRepository();
        $this->state = new WaterAlertRepository();
        $this->sent = [];
    }

    private function alert(): WaterAlert
    {
        return new WaterAlert(
            new WaterLevel($this->history),
            $this->history,
            $this->state,
            function (string $text): void { $this->sent[] = $text; }
        );
    }

    /** Уровень, дающий нужный запас до кромки моста (−300 см от нуля поста). */
    private function levelForGap(float $gapCm): float
    {
        return WaterLevel::BRIDGE_CM - $gapCm;
    }

    private function measure(string $at, float $gapCm, float $change = 0.0): void
    {
        $this->history->save($at, $this->levelForGap($gapCm), $change);
    }

    public function test_silent_on_first_run_when_water_is_calm(): void
    {
        $this->measure('2026-09-18 10:00:00', 749.0);

        $this->assertNull($this->alert()->check('2026-09-18 10:05:00'));
        $this->assertSame([], $this->sent, 'о спокойной воде в группу не пишем');

        $state = $this->state->state();
        $this->assertSame('calm', $state['status'], 'но состояние запоминаем — по нему ловим скачок шкалы');
        $this->assertNull($state['notified_at'], 'сообщений ещё не было');
    }

    public function test_silence_still_tracks_level(): void
    {
        $this->measure('2026-09-18 10:00:00', 700.0);
        $this->alert()->check('2026-09-18 10:05:00');

        $this->measure('2026-09-18 11:00:00', 650.0);
        $this->assertNull($this->alert()->check('2026-09-18 11:05:00'));
        $this->assertSame(
            $this->levelForGap(650.0),
            (float) $this->state->state()['level_cm'],
            'уровень в состоянии догоняет свежий замер'
        );
    }

    public function test_warns_when_water_approaches_bridge(): void
    {
        $this->measure('2026-09-18 10:00:00', 120.0, 40.0);

        $text = $this->alert()->check('2026-09-18 10:05:00');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Шебш поднимается', $text);
        $this->assertStringContainsString('1,2 м', $text, 'запас до кромки в тексте');
        $this->assertStringContainsString('за сутки поднялась на 40 см', $text);
        $this->assertSame('watch', $this->state->state()['status']);
    }

    public function test_alarms_when_water_is_at_the_bridge(): void
    {
        $this->measure('2026-09-18 10:00:00', 30.0, 90.0);

        $text = $this->alert()->check('2026-09-18 10:05:00');

        $this->assertStringContainsString('Вода у моста', $text);
        $this->assertStringContainsString('30 см', $text);
        $this->assertSame('alert', $this->state->state()['status']);
    }

    public function test_reports_flooded_bridge(): void
    {
        $this->measure('2026-09-18 10:00:00', -20.0, 120.0);

        $text = $this->alert()->check('2026-09-18 10:05:00');

        $this->assertStringContainsString('Мост под водой', $text);
        $this->assertStringContainsString('20 см', $text);
    }

    public function test_does_not_repeat_same_status_within_timeout(): void
    {
        $this->measure('2026-09-18 10:00:00', 120.0);
        $this->alert()->check('2026-09-18 10:05:00');

        $this->measure('2026-09-18 11:00:00', 110.0);
        $this->assertNull($this->alert()->check('2026-09-18 11:05:00'), 'обстановка та же — молчим');
        $this->assertCount(1, $this->sent);
    }

    public function test_repeats_alert_after_timeout(): void
    {
        $this->measure('2026-09-18 10:00:00', 30.0);
        $this->alert()->check('2026-09-18 10:05:00');

        $this->measure('2026-09-18 13:00:00', 28.0);
        $this->assertNull($this->alert()->check('2026-09-18 13:05:00'), 'три часа — ещё рано');

        $this->measure('2026-09-18 16:00:00', 25.0);
        $this->assertNotNull($this->alert()->check('2026-09-18 16:05:00'), 'тревога затянулась — напоминаем');
        $this->assertCount(2, $this->sent);
    }

    public function test_escalates_from_watch_to_alert(): void
    {
        $this->measure('2026-09-18 10:00:00', 120.0);
        $this->alert()->check('2026-09-18 10:05:00');

        $this->measure('2026-09-18 11:00:00', 40.0);
        $text = $this->alert()->check('2026-09-18 11:05:00');

        $this->assertStringContainsString('Вода у моста', $text, 'ухудшение важнее таймера');
    }

    public function test_announces_retreat(): void
    {
        $this->measure('2026-09-18 10:00:00', 40.0);
        $this->alert()->check('2026-09-18 10:05:00');

        $this->measure('2026-09-18 12:00:00', 260.0, -220.0);
        $text = $this->alert()->check('2026-09-18 12:05:00');

        $this->assertStringContainsString('Вода отступила', $text);
        $this->assertSame('calm', $this->state->state()['status']);
    }

    public function test_ignores_jump_towards_calm_water(): void
    {
        $this->measure('2026-09-18 10:00:00', 120.0);
        $this->alert()->check('2026-09-18 10:05:00');
        $this->sent = [];

        // Источник уехал на 9 метров «в безопасную сторону» — ложный отбой не даём.
        $this->measure('2026-09-18 11:00:00', 1000.0);
        $this->assertNull($this->alert()->check('2026-09-18 11:05:00'));
        $this->assertSame([], $this->sent, 'ложный отбой опаснее лишней строки в логе');
        $this->assertSame('calm', $this->state->state()['status'], 'состояние всё равно догоняет источник');
    }

    public function test_warns_despite_jump_when_water_rises_fast(): void
    {
        $this->measure('2026-09-18 10:00:00', 800.0);
        $this->alert()->check('2026-09-18 10:05:00');

        // Ливневый паводок: вода поднялась на 7 метров за час. Молчать нельзя.
        $this->measure('2026-09-18 11:00:00', 30.0, 700.0);
        $text = $this->alert()->check('2026-09-18 11:05:00');

        $this->assertNotNull($text, 'быстрый подъём — это и есть повод написать');
        $this->assertStringContainsString('Вода у моста', $text);
        $this->assertStringContainsString('проверьте обстановку лично', $text, 'скачок оговариваем');
    }

    public function test_silent_without_history(): void
    {
        $this->assertNull($this->alert()->check('2026-09-18 10:05:00'));
        $this->assertSame([], $this->sent);
    }
}
