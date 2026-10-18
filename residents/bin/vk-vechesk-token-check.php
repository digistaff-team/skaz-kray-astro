<?php
declare(strict_types=1);
/** Автономный режим: bootstrap поднимает только .env + конфиг, без сессий и БД. */
define('SKAZ_CRON_NO_DB', true);

require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

/**
 * Проверка токена VECHESK_GROUP_VK_TOKEN (config/.env на сервере): один вызов
 * users.get — «OK» = токен живой, ошибка VK = что не так.
 * Запуск на сервере: php /var/www/skaz-residents/bin/vk-vechesk-token-check.php
 */

// Секреты без автозагрузки (скрипт может работать до vendor/autoload).
require_once __DIR__ . '/../src/Env.php';
\SkazResidents\Env::load('/var/www/skaz-residents/config/.env');   // переменная VECHESK_GROUP_VK_TOKEN

$token = trim((string) (getenv('VECHESK_GROUP_VK_TOKEN') ?: ''));
if ($token === '') {
    fwrite(STDERR, "нет VECHESK_GROUP_VK_TOKEN в config/.env\n");
    exit(1);
}

$ch = curl_init('https://api.vk.com/method/users.get?access_token=' . urlencode($token) . '&v=5.199');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = json_decode((string) $raw, true);
if (is_array($data) && isset($data['error'])) {
    echo 'VK-ошибка ' . (int) $data['error']['error_code'] . ': '
        . (string) ($data['error']['error_msg'] ?? '') . "\n";
    exit(1);
}
if (!is_array($data) || !isset($data['response'])) {
    echo 'HTTP ' . $code . ', не JSON или нет response: ' . mb_substr((string) $raw, 0, 300) . "\n";
    exit(1);
}
echo 'OK: токен живой, ' . count($data['response']) . " пол(я) отдано\n";