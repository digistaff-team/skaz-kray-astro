<?php
declare(strict_types=1);

/**
 * Публикация и закрепление ссылки на портал жителей в группе Telegram.
 *
 * Зачем: Telegram спрашивает подтверждение «вы открываете мини-приложение»
 * каждый раз, когда ссылку открывают оттуда, где не виден полный адрес —
 * из inline-кнопки или из текста со спрятанной гиперссылкой. Ссылка, написанная
 * открытым текстом, спрашивает подтверждение только при первом запуске, поэтому
 * закреп в группе делаем именно таким.
 *
 * Запуск (на сервере, где лежит config/):
 *   php8.3 bin/pin-app-link.php --dry-run   # показать текст и права бота
 *   php8.3 bin/pin-app-link.php             # отправить и закрепить
 *   php8.3 bin/pin-app-link.php --no-pin    # только отправить, без закрепления
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Env, TelegramBot};

$dryRun = in_array('--dry-run', $argv, true);
$noPin  = in_array('--no-pin', $argv, true);

Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');

$tg      = Config::get('telegram');
$token   = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
$chatId  = is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '';
$appLink = (string) (Config::get('residents_app_link', 'https://t.me/SkazKray_bot/app') ?: 'https://t.me/SkazKray_bot/app');

if ($token === '' || $chatId === '') {
    fwrite(STDERR, "Нет токена бота или chat_id группы в config/config.php\n");
    exit(1);
}

$text = implode(PHP_EOL, [
    'Приложение жителей ПРП «Сказочный Край»🍀',
    '',
    'Дневники поместий, инструменты и книги, поездки, совместные закупки,',
    'Общий дом, карта и справочник поместий — всё в одном приложении!😃👌🏻',
    '',
    $appLink,
]);

echo "---- текст сообщения ----\n{$text}\n-------------------------\n";

// Права бота в группе: закрепить сможет только админ с can_pin_messages.
$botId = (int) explode(':', $token)[0];
$me = TelegramBot::myChatMember($token, $chatId, $botId);
if ($me === null) {
    echo "Права бота в группе выяснить не удалось (нет ответа Bot API).\n";
} else {
    $status = (string) ($me['status'] ?? '?');
    $canPin = !empty($me['can_pin_messages']) || $status === 'creator';
    echo "Бот в группе: {$status}, закреплять " . ($canPin ? 'может' : 'НЕ может') . "\n";
    if (!$canPin && !$noPin && !$dryRun) {
        echo "Сообщение отправим, но закрепить не выйдет — выдайте боту право «Закреплять сообщения».\n";
    }
}

if ($dryRun) {
    echo "[DRY-RUN] Ничего не отправлено.\n";
    exit(0);
}

$messageId = TelegramBot::sendMessageId($token, $chatId, $text);
if ($messageId === null) {
    fwrite(STDERR, "Сообщение не отправлено — смотрите error_log.\n");
    exit(1);
}
echo "Отправлено, message_id = {$messageId}\n";

if ($noPin) { exit(0); }

echo TelegramBot::pinMessage($token, $chatId, $messageId)
    ? "Закреплено в группе.\n"
    : "Закрепить не удалось — проверьте право бота «Закреплять сообщения».\n";
