<?php
// Копия этого файла как config/config.php (вне git) заполняется на сервере.
return [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=skazkray_residents;charset=utf8mb4',
        'user' => 'skaz_residents',
        'pass' => 'CHANGE_ME',
    ],
    'smtp' => [
        'host'      => 'smtp.skaz-kray.ru',
        'port'      => 465,
        'secure'    => 'ssl',            // ssl (465) или tls (587)
        'user'      => 'noreply@skaz-kray.ru',
        'pass'      => 'CHANGE_ME',
        'from'      => 'noreply@skaz-kray.ru',
        'from_name' => 'Сказочный Край',
    ],
    'base_url'     => 'https://skaz-kray.ru',
    'uploads_dir'  => __DIR__ . '/../public/uploads',   // куда пишем файлы
    'uploads_url'  => '/poselenie/uploads',             // как отдаём (nginx)
    'session_name' => 'skazres',
    'reset_ttl'    => 3600,                              // срок жизни токена сброса, сек
    'login_throttle' => ['max' => 5, 'window' => 900],  // 5 попыток за 15 мин
];
