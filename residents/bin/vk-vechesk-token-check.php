<?php
declare(strict_types=1);
/** Автономный режим: bootstrap поднимает только .env + конфиг, без сессий и БД. */
define('SKAZ_CRON_NO_DB', true);

require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

/**
 * Проверка сервисного ключа VK_VECHESK_SERVICE_TOKEN (config/.env на сервере):
 * два вызова — wall.get и groups.getMembers. «OK» по обоим = ключ годится для
 * пересылки постов ВК-группы «Вече»; ошибка VK печатается с кодом и текстом.
 *
 * Проверяем именно эти методы, а не users.get: у сервисного ключа нет
 * пользователя, а groups.getMembers нужен для сверки подписчиков.
 *
 * Запуск на сервере: php /var/www/skaz-residents/bin/vk-vechesk-token-check.php
 */

// Секреты без автозагрузки (скрипт может работать до vendor/autoload).
require_once __DIR__ . '/../src/Env.php';
\SkazResidents\Env::load(__DIR__ . '/../config/.env');   // переменная VK_VECHESK_SERVICE_TOKEN

$token = trim((string) (getenv('VK_VECHESK_SERVICE_TOKEN') ?: ''));
if ($token === '') {
    fwrite(STDERR, "нет VK_VECHESK_SERVICE_TOKEN в config/.env\n");
    exit(1);
}

/** @return array{ok:bool,text:string} */
$check = static function (string $method, array $params) use ($token): array {
    $params += ['access_token' => $token, 'v' => '5.199'];
    $url = 'https://api.vk.com/method/' . $method . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if (is_array($data) && isset($data['error'])) {
        return ['ok' => false, 'text' => 'ошибка ' . (int) $data['error']['error_code']
            . ': ' . (string) ($data['error']['error_msg'] ?? '')];
    }
    if (!is_array($data) || !isset($data['response'])) {
        return ['ok' => false, 'text' => 'HTTP ' . $code . ', не JSON или нет response: '
            . mb_substr((string) $raw, 0, 300)];
    }
    $count = is_array($data['response']) ? (int) ($data['response']['count'] ?? 0) : 0;
    return ['ok' => true, 'text' => 'OK, записей: ' . $count];
};

$failed = false;
foreach ([
    'wall.get'          => ['owner_id' => '-63502598', 'count' => 3],
    'groups.getMembers' => ['group_id' => 63502598, 'count' => 3],
] as $method => $params) {
    $res = $check($method, $params);
    echo str_pad($method, 20) . ' ' . $res['text'] . "\n";
    if (!$res['ok']) { $failed = true; }
}
exit($failed ? 1 : 0);
