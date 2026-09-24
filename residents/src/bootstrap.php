<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Env;

// Секреты из окружения (config/.env, вне git) — config.php читает их через getenv().
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');

/*
 * Сессия живёт 30 дней с последнего визита.
 *
 * По умолчанию PHP держал её 24 минуты простоя, после чего житель оказывался на
 * странице входа по паролю — а у пришедших из Telegram и MAX пароля нет вовсе.
 *
 * Своих настроек недостаточно: на Debian сессии в общем каталоге чистит cron
 * (/usr/lib/php/sessionclean), и срок он берёт из php.ini, а не из ini_set.
 * Поэтому храним сессии в своём каталоге, куда cron не заглядывает, и чистим
 * его сами встроенным сборщиком PHP (1% запросов).
 */
const SESSION_TTL = 30 * 24 * 3600;
$sessionDir = __DIR__ . '/../var/sessions';
if (!is_dir($sessionDir)) { @mkdir($sessionDir, 0700, true); }
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
} else {
    // Без своего каталога сессии уйдут в общий — и cron снова срежет их до 24 минут.
    error_log('bootstrap: каталог сессий недоступен, ' . $sessionDir);
}
ini_set('session.gc_maxlifetime', (string) SESSION_TTL);

$sessionCookie = [
    'lifetime' => SESSION_TTL,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
session_name(Config::get('session_name', 'skazres'));
session_set_cookie_params($sessionCookie);
session_start();

// PHP ставит куку заново только при смене идентификатора, то есть её 30 дней
// отсчитывались бы от входа, а не от последнего визита. Продлеваем её сами —
// и только вошедшим: анонимную сессию на месяц держать незачем.
if (isset($_SESSION['family_id']) || isset($_SESSION['council_id'])) {
    setcookie(session_name(), session_id(), [
        'expires'  => time() + SESSION_TTL,
        'path'     => $sessionCookie['path'],
        'secure'   => $sessionCookie['secure'],
        'httponly' => $sessionCookie['httponly'],
        'samesite' => $sessionCookie['samesite'],
    ]);
}

// Хелперы шаблонов — отдельным файлом, чтобы их могли брать и тесты.
require __DIR__ . '/helpers.php';


Database::connect(Config::get('db'));
