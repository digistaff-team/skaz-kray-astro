<?php
declare(strict_types=1);

/**
 * Учёт и накат SQL-миграций из config/ по списку config/migrations.txt.
 * Применённые записываются в таблицу schema_migrations.
 *
 * Запуск на сервере ОТ ROOT (DDL app-юзеру нельзя; root MySQL входит по сокету):
 *   php8.3 bin/migrate.php status               — что применено, что ждёт (код 2, если ждёт)
 *   php8.3 bin/migrate.php up                   — накатить ждущие по порядку
 *   php8.3 bin/migrate.php baseline <файл.sql>  — отметить применёнными все по <файл>
 *                                                 включительно, НЕ выполняя их (для базы,
 *                                                 где их уже накатывали вручную)
 * Опция --db=<имя> — другая БД (по умолчанию skazkray_residents).
 *
 * Каждый файл выполняется клиентом mysql — так же, как при ручном накате. На
 * первой ошибке накат останавливается; упавший файл не отмечается применённым.
 * В MySQL DDL не транзакционен: если файл упал на середине, его начало уже
 * применено — поправьте файл так, чтобы повтор был безопасен (IF NOT EXISTS).
 */

require __DIR__ . '/../src/timezone.php';     // время приложения — Москва (UTC+3)
require __DIR__ . '/../src/Migrations.php';   // без autoload: скрипт гоняется и из временного каталога deploy.sh

use SkazResidents\Migrations;

$args = array_slice($argv, 1);
$db = 'skazkray_residents';
foreach ($args as $i => $a) {
    if (str_starts_with($a, '--db=')) { $db = substr($a, 5); unset($args[$i]); }
}
$args = array_values($args);
$cmd = $args[0] ?? 'status';

if (!preg_match('~^[A-Za-z0-9_]+$~', $db)) { fwrite(STDERR, "Недопустимое имя БД: {$db}\n"); exit(1); }

$configDir = __DIR__ . '/../config';
$manifest = Migrations::manifest((string) file_get_contents($configDir . '/migrations.txt'));
$files = array_map('basename', glob($configDir . '/*.sql') ?: []);
[$unlisted, $missing, $dups] = Migrations::problems($manifest, $files);
if ($missing || $dups) {
    fwrite(STDERR, "config/migrations.txt не сходится с файлами. Нет файла: " . implode(', ', $missing)
        . "; повторы: " . implode(', ', $dups) . "\n");
    exit(1);
}
if ($unlisted) {
    fwrite(STDERR, "Внимание: этих .sql нет в config/migrations.txt, они не накатываются: " . implode(', ', $unlisted) . "\n");
}

/** Выполнить SQL клиентом mysql; stdin — строка или файл. Возвращает [код, вывод]. */
function mysql_run(string $db, string $sql = '', ?string $file = null): array
{
    $cmd = 'mysql --batch --skip-column-names --default-character-set=utf8mb4 ' . escapeshellarg($db);
    $spec = [0 => $file !== null ? ['file', $file, 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $spec, $pipes);
    if (!is_resource($p)) { return [1, 'не удалось запустить mysql']; }
    if ($file === null) { fwrite($pipes[0], $sql); fclose($pipes[0]); }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), trim($out)];
}

function mysql_ok(string $db, string $sql): string
{
    [$code, $out] = mysql_run($db, $sql);
    if ($code !== 0) { fwrite(STDERR, "Ошибка MySQL: {$out}\n"); exit(1); }
    return $out;
}

function mark_applied(string $db, string $name): void
{
    mysql_ok($db, "INSERT IGNORE INTO schema_migrations (name) VALUES ('" . addslashes($name) . "');");
}

mysql_ok($db, 'CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
$raw = mysql_ok($db, 'SELECT name FROM schema_migrations ORDER BY name;');
$applied = $raw === '' ? [] : explode("\n", $raw);
$pending = Migrations::pending($manifest, $applied);

switch ($cmd) {
    case 'status':
        echo 'Применено: ' . count(array_intersect($manifest, $applied)) . ' из ' . count($manifest) . "\n";
        if (!$pending) { echo "Ждущих миграций нет.\n"; exit(0); }
        echo "Ждут наката:\n";
        foreach ($pending as $n) { echo "  {$n}\n"; }
        exit(2);

    case 'up':
        if (!$pending) { echo "Ждущих миграций нет.\n"; exit(0); }
        foreach ($pending as $n) {
            echo "→ {$n} ... ";
            [$code, $out] = mysql_run($db, '', $configDir . '/' . $n);
            if ($code !== 0) {
                echo "ОШИБКА\n{$out}\nНакат остановлен; {$n} не отмечен применённым.\n";
                exit(1);
            }
            mark_applied($db, $n);
            echo "ok\n";
        }
        echo 'Готово: применено ' . count($pending) . ".\n";
        exit(0);

    case 'baseline':
        $last = $args[1] ?? '';
        if ($last === '') { fwrite(STDERR, "Укажите последний уже применённый файл: baseline <файл.sql>\n"); exit(1); }
        try {
            $names = Migrations::upTo($manifest, $last);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        foreach ($names as $n) { mark_applied($db, $n); }
        echo 'Отмечено применёнными (без выполнения): ' . count($names) . ", по {$last} включительно.\n";
        exit(0);

    default:
        fwrite(STDERR, "Команды: status | up | baseline <файл.sql>\n");
        exit(1);
}
