<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Проверка членства пользователя в Telegram-группе жителей через Bot API
 * getChatMember. Требует, чтобы бот @SkazKray_bot был участником/админом группы.
 *
 * В отличие от abconsult (fail-open), здесь **fail-closed**: это основной вход
 * в закрытый портал, поэтому при ошибке проверки доступ НЕ выдаётся (возвращаем
 * 'error' — контроллер показывает «проверка недоступна», но внутрь не пускает).
 */
final class TelegramSubscription
{
    private const SUBSCRIBED = ['member', 'administrator', 'creator'];

    /** @return 'subscribed'|'not_subscribed'|'error' */
    public static function status(string $telegramId, string $botToken, string $chatId): string
    {
        if ($botToken === '' || $chatId === '' || $telegramId === '') { return 'error'; }

        $url = 'https://api.telegram.org/bot' . $botToken . '/getChatMember?chat_id='
            . rawurlencode($chatId) . '&user_id=' . rawurlencode($telegramId);

        $raw = self::httpGet($url);
        if ($raw === null) { error_log('TelegramSubscription: запрос getChatMember не удался'); return 'error'; }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok']) || !isset($data['result']['status'])) {
            error_log('TelegramSubscription: неожиданный ответ Bot API: ' . mb_substr($raw, 0, 200));
            return 'error';
        }
        return in_array($data['result']['status'], self::SUBSCRIBED, true) ? 'subscribed' : 'not_subscribed';
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false || $code !== 200) { return null; }
            return (string) $res;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : $res;
    }
}
