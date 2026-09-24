<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Приложение живёт по московскому времени (UTC+3), хотя сервер — в UTC.
 * Пояс задаёт src/timezone.php; его же подключают веб, консольные скрипты и тесты.
 */
final class TimezoneTest extends TestCase
{
    public function test_app_runs_in_moscow_time(): void
    {
        $this->assertSame('Europe/Moscow', date_default_timezone_get());
        $this->assertSame('+03:00', date('P'));
    }

    public function test_every_console_script_sets_the_timezone(): void
    {
        // Кроны не подключают src/bootstrap.php — каждый обязан задать пояс сам,
        // иначе крон и сайт разойдутся в том, какое сегодня число.
        $missing = [];
        foreach (glob(__DIR__ . '/../bin/*.php') as $script) {
            if (!str_contains((string) file_get_contents($script), "src/timezone.php")) {
                $missing[] = basename($script);
            }
        }
        $this->assertSame([], $missing, 'Эти консольные скрипты не задают часовой пояс');
    }

    public function test_web_bootstrap_sets_the_timezone(): void
    {
        // src/bootstrap.php лежит рядом с timezone.php и подключает его сам.
        $this->assertStringContainsString("require __DIR__ . '/timezone.php';",
            (string) file_get_contents(__DIR__ . '/../src/bootstrap.php'));
    }
}
