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

Database::connect(Config::get('db'));
