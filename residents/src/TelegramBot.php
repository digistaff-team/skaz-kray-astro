<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Минимальный отправитель сообщений через Telegram Bot API (@SkazKray_bot).
 * Используется для рассылки уведомлений членам совета (bin/council-meeting-notify.php).
 */
final class TelegramBot
{
    /**
     * Отправить текстовое сообщение пользователю. true — доставлено Bot API.
     * Доступ к api.telegram.org с сервера бывает нестабилен (таймауты) — до 3 попыток.
     * $parseMode — 'HTML'|'MarkdownV2'|null; $replyMarkup — JSON inline-клавиатуры.
     */
    public static function sendMessage(string $botToken, string $chatId, string $text, ?string $parseMode = null, ?string $replyMarkup = null): bool
    {
        if ($botToken === '' || $chatId === '') { return false; }

        $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => '1',
        ];
        if ($parseMode !== null)   { $params['parse_mode']   = $parseMode; }
        if ($replyMarkup !== null) { $params['reply_markup'] = $replyMarkup; }
        $payload = http_build_query($params);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = self::httpPost($url, $payload);
            if ($raw !== null) {
                $data = json_decode($raw, true);
                if (is_array($data) && !empty($data['ok'])) { return true; }
                // Ответ есть, но не ок (403/400 и т.п.) — повтор не поможет.
                error_log('TelegramBot::sendMessage не ок для ' . $chatId . ': ' . mb_substr((string) $raw, 0, 200));
                return false;
            }
            if ($attempt < 3) { sleep(2); }   // сетевой сбой — пробуем ещё
        }
        return false;
    }

    /** Ответить на нажатие inline-кнопки (обязательно, иначе у пользователя «часики»). */
    public static function answerCallback(string $botToken, string $callbackId, string $text = ''): void
    {
        if ($botToken === '' || $callbackId === '') { return; }
        self::httpPost(
            'https://api.telegram.org/bot' . $botToken . '/answerCallbackQuery',
            http_build_query(['callback_query_id' => $callbackId, 'text' => $text])
        );
    }

    /** Заменить текст сообщения (reply_markup не шлём → кнопки убираются). */
    public static function editMessageText(string $botToken, string $chatId, int $messageId, string $text): bool
    {
        if ($botToken === '' || $chatId === '') { return false; }
        $raw = self::httpPost(
            'https://api.telegram.org/bot' . $botToken . '/editMessageText',
            http_build_query([
                'chat_id'                  => $chatId,
                'message_id'               => $messageId,
                'text'                     => $text,
                'disable_web_page_preview' => '1',
            ])
        );
        $d = json_decode((string) $raw, true);
        return is_array($d) && !empty($d['ok']);
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
