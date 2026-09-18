<?php
declare(strict_types=1);

/**
 * Часовой снимок уровня воды в Шебше в свою историю (water_level_history).
 * Запускается cron'ом раз в час:
 *   5 * * * * /usr/bin/php8.3 /var/www/skaz-residents/bin/water-level-snapshot.php >> /var/log/water-level-snapshot.log 2>&1
 *
 * Спрашивает замер у приложения shebsh-water-level (Vercel), которое скрапит
 * гидропост AllRivers, и пишет строку за текущий час. Свою историю ведём сами:
 * чужой /api/history живёт в Vercel KV и уже отдавал 502. Повторный запуск в
 * тот же час не плодит строки (первичный ключ measured_at).
 *
 * Флаг: --dry-run (спросить источник и показать, ничего не записывая).
 *
 * Запуск: php8.3 bin/water-level-snapshot.php [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Database, Env};
use SkazResidents\Service\WaterLevel;

$dryRun = in_array('--dry-run', $argv, true);

// В CLI bootstrap не подключается — конфиг и БД поднимаем явно, как в соседних bin/.
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));

$now   = date('Y-m-d H:i:s');
$water = new WaterLevel();
$stamp = fn(string $msg): string => '[' . $now . '] ' . $msg;

if ($dryRun) {
    $data = $water->probe();
    if ($data === null) {
        fwrite(STDERR, $stamp('источник не ответил') . PHP_EOL);
        exit(1);
    }
    echo $stamp(sprintf(
        'dry-run: уровень %.2f см от нуля поста (%.2f м БСВ), за сутки %+.0f см, до моста %.2f м',
        $data['level_cm'],
        $water->bsv($data['level_cm']),
        $data['change_24h'],
        $water->bridgeGapCm($data['level_cm']) / 100
    )) . PHP_EOL;
    exit(0);
}

$data = $water->snapshot($now);
if ($data === null) {
    fwrite(STDERR, $stamp('источник не ответил, замер не записан') . PHP_EOL);
    exit(1);
}

echo $stamp(sprintf(
    'записан замер %.2f см (%.2f м БСВ), до моста %.2f м',
    $data['level_cm'],
    $water->bsv($data['level_cm']),
    $water->bridgeGapCm($data['level_cm']) / 100
)) . PHP_EOL;
