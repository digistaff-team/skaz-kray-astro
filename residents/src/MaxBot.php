<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Минимальный отправитель сообщений через Bot API MAX (@SkazKray_bot в MAX).
 * Используется для рассылки уведомлений членам совета (bin/council-meeting-notify.php),
 * аналог {@see TelegramBot} для платформы MAX.
 *
 * База: https://botapi.max.ru; авторизация — заголовком «Authorization: <token>»
 * (query-параметр access_token признан устаревшим). Отправка сообщения:
 *   POST /messages?user_id={id}  тело JSON {"text": "..."}.
 * https://dev.max.ru/docs-api
 */
final class MaxBot
{
    private const BASE = 'https://botapi.max.ru';

    /**
     * Отправить текстовое сообщение пользователю (в диалог с ботом). true — принято
     * Bot API. Доступ к botapi.max.ru с сервера бывает нестабилен — до 3 попыток.
     */
    public static function sendMessage(string $botToken, string $userId, string $text): bool
    {
        if ($botToken === '' || $userId === '') { return false; }

        $url  = self::BASE . '/messages?user_id=' . rawurlencode($userId) . '&disable_link_preview=true';
        $body = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = self::httpPost($url, (string) $body, $botToken);
            if ($raw !== null) {
                $data = json_decode($raw, true);
                // Успех: пришёл объект отправленного сообщения (без поля error-кода).
                if (is_array($data) && !isset($data['code']) && isset($data['message'])) {
                    return true;
                }
                // Ответ есть, но с ошибкой (dialog.not.found, verify.token и т.п.) —
                // повтор не поможет.
                error_log('MaxBot::sendMessage не ок для ' . $userId . ': ' . mb_substr((string) $raw, 0, 200));
                return false;
            }
            if ($attempt < 3) { sleep(2); }   // сетевой сбой — пробуем ещё
        }
        return false;
    }

    /**
     * Отправить сообщение в чат/группу MAX (в отличие от sendMessage — не в диалог
     * с ботом, а по chat_id; бот должен состоять в этой группе).
     */
    public static function sendToChat(string $botToken, string $chatId, string $text): bool
    {
        if ($botToken === '' || $chatId === '') { return false; }

        $url  = self::BASE . '/messages?chat_id=' . rawurlencode($chatId) . '&disable_link_preview=true';
        $body = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = self::httpPost($url, (string) $body, $botToken);
            if ($raw !== null) {
                $data = json_decode($raw, true);
                if (is_array($data) && !isset($data['code']) && isset($data['message'])) { return true; }
                error_log('MaxBot::sendToChat не ок для чата ' . $chatId . ': ' . mb_substr((string) $raw, 0, 200));
                return false;
            }
            if ($attempt < 3) { sleep(2); }   // сетевой сбой — пробуем ещё
        }
        return false;
    }

    private static function httpPost(string $url, string $body, string $token): ?string
    {
        $headers = ['Authorization: ' . $token, 'Content-Type: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false) { error_log('MaxBot: curl ошибка: ' . $err); return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
