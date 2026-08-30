<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Минимальный загрузчик .env (без сторонних зависимостей). Читает файл вида
 * KEY=value (по строке), пропускает пустые строки и комментарии (#), снимает
 * обрамляющие кавычки. Значения кладутся в окружение процесса (putenv/$_ENV),
 * откуда их читает config.php через getenv(). Реальные переменные окружения
 * (заданные системой/пулом FPM) имеют приоритет и не перезаписываются.
 *
 * Секреты (напр. токен бота) держим в config/.env (вне git, chmod 640), а не
 * в коде и не в config.php.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) { return; }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) { return; }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $eq = strpos($line, '=');
            if ($eq === false) { continue; }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if ($key === '') { continue; }
            $len = strlen($val);
            if ($len >= 2
                && (($val[0] === '"' && $val[$len - 1] === '"')
                    || ($val[0] === "'" && $val[$len - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false) { // не переопределяем реальные env
                putenv($key . '=' . $val);
                $_ENV[$key] = $val;
            }
        }
    }
}
