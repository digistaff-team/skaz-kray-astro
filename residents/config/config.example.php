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
    // Авто-логин жителя через Telegram Mini App (@SkazKray_bot).
    // Секрет — токен — берётся из окружения (config/.env: SKAZKRAY_BOT_TOKEN),
    // не хранится в этом файле. chat_id группы и ссылка — не секреты, можно тут.
    'telegram' => [
        'bot_token'     => getenv('SKAZKRAY_BOT_TOKEN') ?: '',
        'group_chat_id' => '-1001580770653',          // группа жителей для getChatMember (супергруппа: -100 + id)
        'group_link'    => 'https://t.me/+CHANGE_ME',  // ссылка-приглашение в группу (для экрана гейта)
        // Секрет webhook бота (X-Telegram-Bot-Api-Secret-Token) для кнопок под
        // уведомлениями о задачах. Задаётся при setWebhook (bin/council-set-webhook.php).
        'webhook_secret' => getenv('SKAZKRAY_WEBHOOK_SECRET') ?: '',
    ],
    // Фото дневника уходят в приватный Telegram-канал «Skaz-Kray Media» (тот же,
    // что у новостей блога) и отдаются через /tg-media/<file_id>.jpg. Чтобы не
    // дублировать секрет, на проде подключаем общий конфиг того же канала:
    //   'tg_media' => require '/var/www/new.skaz-kray.ru/html/tg-media/config.php',
    // (файл возвращает ['bot_token'=>..., 'chat_id'=>...]). Без ключа фото падают
    // в локальный uploads (фолбэк).
    'tg_media'     => ['bot_token' => '', 'chat_id' => ''],
    'base_url'     => 'https://skaz-kray.ru',
    // Ссылка, открывающая раздел Совета как Telegram Mini App — для рассылки
    // уведомления о встрече (bin/council-meeting-notify.php).
    'council_app_link' => 'https://t.me/SkazKray_bot/sovet',
    'uploads_dir'  => __DIR__ . '/../public/uploads',   // куда пишем файлы
    'uploads_url'  => '/poselenie/uploads',             // как отдаём (nginx)
    'session_name' => 'skazres',
    'reset_ttl'    => 3600,                              // срок жизни токена сброса, сек
    'login_throttle' => ['max' => 5, 'window' => 900],  // 5 попыток за 15 мин
];
