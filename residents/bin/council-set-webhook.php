<?php
declare(strict_types=1);

/**
 * Установка/снятие webhook @SkazKray_bot для кнопок под уведомлениями о задачах.
 * ВНИМАНИЕ: webhook у бота ОДИН. Установка нашего ОТКЛЮЧАЕТ прежний (ProTalk).
 *
 *   php8.3 bin/council-set-webhook.php          — поставить наш webhook
 *   php8.3 bin/council-set-webhook.php --delete — снять (вернуть боту «пустой» webhook)
 *   php8.3 bin/council-set-webhook.php --info    — показать текущий webhook
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Env};

Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');

$tg     = Config::get('telegram');
$token  = (string) ($tg['bot_token'] ?? '');
$secret = (string) ($tg['webhook_secret'] ?? '');
$base   = rtrim((string) Config::get('base_url', 'https://skaz-kray.ru'), '/');
$hook   = $base . '/sovet/tg/webhook';

$api = static function (string $method, array $params = []) use ($token): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($params), 'timeout' => 20, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/' . $method, false, $ctx);
    return json_decode((string) $raw, true) ?: ['ok' => false, 'raw' => $raw];
};

if (in_array('--info', $argv, true)) {
    echo json_encode($api('getWebhookInfo'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if (in_array('--delete', $argv, true)) {
    echo "deleteWebhook: " . json_encode($api('deleteWebhook', ['drop_pending_updates' => 'false']), JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if ($secret === '') {
    fwrite(STDERR, "Не задан telegram.webhook_secret (env SKAZKRAY_WEBHOOK_SECRET в config/.env)\n");
    exit(1);
}

$res = $api('setWebhook', [
    'url'             => $hook,
    'secret_token'    => $secret,
    'allowed_updates' => json_encode(['callback_query']),
    'drop_pending_updates' => 'true',
]);
echo "setWebhook ({$hook}): " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
echo "getWebhookInfo: " . json_encode($api('getWebhookInfo'), JSON_UNESCAPED_UNICODE) . "\n";
