<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Env;

// Секреты из окружения (config/.env, вне git) — config.php читает их через getenv().
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');

$session = Config::get('session_name', 'skazres');
session_name($session);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function status_label(string $status): string
{
    return match ($status) {
        'pending'   => 'на проверке',
        'published' => 'опубликовано',
        'rejected'  => 'отклонено',
        default     => $status,
    };
}

/**
 * "2026-08-29 10:00:00" -> "29 августа 2026". Формат идентичен ruDate()
 * из src/lib/utils.js (внешний Astro-сайт) — для визуального единообразия
 * публичных страниц раздела жителей с остальным сайтом.
 */
function ru_date(?string $s): string
{
    if (!$s) { return ''; }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) { return $s; }
    static $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    return ((int) $m[3]) . ' ' . $months[((int) $m[2]) - 1] . ' ' . $m[1];
}

/** Русское склонение: plural_ru(2, 'задача', 'задачи', 'задач'). */
function plural_ru(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) { return $many; }
    if ($n1 > 1 && $n1 < 5) { return $few; }
    if ($n1 === 1) { return $one; }
    return $many;
}

/** Строка статуса дневника для мобильного лаунчера. $d = ['latestStatus', ...]. */
function diary_status_line(array $d): string
{
    $map = ['pending' => 'на проверке', 'published' => 'опубликована', 'rejected' => 'отклонена'];
    $st = $map[$d['latestStatus'] ?? ''] ?? 'в дневнике';
    return 'Ваш дневник: последняя запись ' . $st;
}

/**
 * Версия статик-ассета по времени изменения файла — для кэш-бастинга
 * (nginx отдаёт /poselenie/assets/* с 30-дневным кэшем; без ?v= обновления
 * стилей не доходят до вернувшихся посетителей). $rel — путь от public/.
 */
function asset_ver(string $rel): string
{
    $m = @filemtime(__DIR__ . '/../public/' . ltrim($rel, '/'));
    return $m ? (string) $m : '1';
}

/** Грубое определение мобильного браузера по User-Agent (для выбора посадочной). */
function is_mobile_ua(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua);
}

Database::connect(Config::get('db'));
