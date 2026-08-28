<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;

Config::load(__DIR__ . '/../config/config.php');

$session = Config::get('session_name', 'skazres');
session_name($session);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/poselenie',
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

Database::connect(Config::get('db'));
