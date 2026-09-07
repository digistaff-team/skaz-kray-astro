<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Минимальный отправитель сообщений через Telegram Bot API (@SkazKray_bot).
 * Используется для рассылки уведомлений членам совета (bin/council-meeting-notify.php).
 */
final class TelegramBot
{
    /** Отправить текстовое сообщение пользователю. true — доставлено Bot API. */
    public static function sendMessage(string $botToken, string $chatId, string $text): bool
    {
        if ($botToken === '' || $chatId === '') { return false; }

        $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
        $payload = http_build_query([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => '1',
        ]);

        $raw = self::httpPost($url, $payload);
        if ($raw === null) { return false; }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            error_log('TelegramBot::sendMessage не ок для ' . $chatId . ': ' . mb_substr((string) $raw, 0, 200));
            return false;
        }
        return true;
    }

    private static function httpPost(string $url, string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false) { error_log('TelegramBot: curl ошибка: ' . $err); return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
