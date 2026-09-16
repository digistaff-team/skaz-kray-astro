<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Проверка членства пользователя в группе жителей в MAX через Bot API MAX
 * (аналог {@see TelegramSubscription} для платформы MAX). Требует, чтобы бот
 * @SkazKray_bot был участником/админом группы.
 *
 * Метод: GET https://botapi.max.ru/chats/{chatId}/members?user_ids={id}
 * (заголовок «Authorization: <token>»). Возвращает {"members":[...]} — только
 * найденных участников; пусто → не состоит. fail-closed: при ошибке доступ НЕ
 * выдаётся (это основной вход в закрытый портал с ПДн).
 *
 * ВНИМАНИЕ: точный контракт members-эндпоинта не удалось статически сверить с
 * dev.max.ru (SPA-документация); реализовано по совместимому с TamTam Bot API
 * образцу. Проверить вживую нужно после того, как бот будет добавлен в MAX-группу
 * жителей (тогда же — {@see MaxSubscription} на реальном chatId).
 */
final class MaxSubscription
{
    private const BASE = 'https://botapi.max.ru';

    /** @return 'subscribed'|'not_subscribed'|'error' */
    public static function status(string $maxUserId, string $botToken, string $chatId): string
    {
        if ($botToken === '' || $chatId === '' || $maxUserId === '') { return 'error'; }

        $url = self::BASE . '/chats/' . rawurlencode($chatId) . '/members?user_ids=' . rawurlencode($maxUserId);

        $raw = self::httpGet($url, $botToken);
        if ($raw === null) { error_log('MaxSubscription: запрос members не удался'); return 'error'; }

        $data = json_decode($raw, true);
        if (!is_array($data) || isset($data['code']) || !isset($data['members']) || !is_array($data['members'])) {
            error_log('MaxSubscription: неожиданный ответ Bot API: ' . mb_substr($raw, 0, 200));
            return 'error';
        }
        foreach ($data['members'] as $m) {
            if (is_array($m) && (string) ($m['user_id'] ?? '') === $maxUserId) {
                return 'subscribed';
            }
        }
        return 'not_subscribed';
    }

    private static function httpGet(string $url, string $token): ?string
    {
        $headers = ['Authorization: ' . $token];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false || $code !== 200) { return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => [
            'header'        => implode("\r\n", $headers),
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
