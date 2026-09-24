<?php
declare(strict_types=1);

/**
 * Заливка прошлых замеров уровня воды в свою историю (water_level_history).
 * Разовая утилита: часовые снимки делает bin/water-level-snapshot.php.
 *
 * Источник по умолчанию — архив приложения shebsh-water-level в его репозитории.
 * Можно передать свой файл или URL, лишь бы отдавал список записей вида
 *   {"created_at":"2026-05-31T14:29:25.100Z","water_level":-93.62,"change_24h":0}
 * (тот же формат у его /api/history, когда Vercel KV жив).
 *
 * Битые записи фильтруются, повторный запуск безопасен: замер за уже занятый
 * час перезаписывается (см. SkazResidents\Service\WaterLevelImport).
 *
 * Флаги:
 *   --from=<файл|URL>   источник (по умолчанию архив в репозитории проекта)
 *   --dry-run           показать, что получится, ничего не записывая
 *
 * Запуск: php8.3 bin/water-level-import.php [--from=...] [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

use SkazResidents\{Config, Database, Env};
use SkazResidents\Service\WaterLevelImport;

const DEFAULT_SOURCE = 'https://raw.githubusercontent.com/digistaff-team/shebsh_water_level/main/public/history.json';

$dryRun = in_array('--dry-run', $argv, true);
$source = DEFAULT_SOURCE;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--from=')) { $source = substr($arg, 7); }
}

// В CLI bootstrap не подключается — конфиг и БД поднимаем явно, как в соседних bin/.
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));

$import  = new WaterLevelImport();
$records = $import->load($source);
if ($records === null) {
    fwrite(STDERR, "Источник недоступен или отдал не список записей: {$source}" . PHP_EOL);
    exit(1);
}

echo 'Источник: ' . $source . PHP_EOL;
echo 'Записей в источнике: ' . count($records) . PHP_EOL;

$result = $import->import($records, $dryRun);

foreach ($result['notes'] as $note) {
    echo '  · ' . $note . PHP_EOL;
}
printf(
    "%s: новых часов %d, перезаписано %d, пропущено %d%s",
    $dryRun ? 'dry-run' : 'записано',
    $result['added'],
    $result['updated'],
    $result['skipped'],
    PHP_EOL
);
