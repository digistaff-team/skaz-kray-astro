<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Проверка initData мини-приложения MAX (аналог Telegram Web Apps). Схема
 * подписи у MAX совпадает с Telegram:
 * https://dev.max.ru/docs/webapps/validation
 *
 * secret_key = HMAC_SHA256(key="WebAppData", msg=botToken);
 * hash = hex(HMAC_SHA256(key=secret_key, msg=launch_params)), где launch_params —
 * все поля кроме hash, url-decoded, отсортированные по ключу, «k=v» через \n.
 * Поле user — URL-decoded JSON (id/first_name/last_name/username/…).
 *
 * Отличие от Telegram: у MAX нет поля signature (Ed25519) — считаем единственный
 * канон (исключаем только hash). См. {@see TelegramWebApp} — там из-за signature
 * две трактовки.
 */
final class MaxWebApp
{
    // MAX рекомендует интервал жизни данных ~1 час; берём сутки для parity с
    // Telegram-входом (меньше трения при возврате в приложение), + допуск на часы.
    private const MAX_AUTH_AGE = 86400;   // 24 часа
    private const CLOCK_SKEW   = 300;     // допуск на рассинхрон часов, 5 мин

    /**
     * Возвращает данные пользователя при валидной подписи и свежем auth_date,
     * иначе null.
     * @return array{id:string,first_name:?string,last_name:?string,username:?string}|null
     */
    public static function verify(string $initData, string $botToken): ?array
    {
        if ($initData === '' || $botToken === '') { return null; }

        // Парсим query-строку вручную (parse_str манглит ключи вида user[...]).
        $params = [];
        foreach (explode('&', $initData) as $chunk) {
            if ($chunk === '') { continue; }
            $eq = strpos($chunk, '=');
            if ($eq === false) { continue; }
            $k = urldecode(substr($chunk, 0, $eq));
            $v = urldecode(substr($chunk, $eq + 1));
            $params[$k] = $v;
        }

        $hash = $params['hash'] ?? null;
        if (!is_string($hash) || $hash === '') { return null; }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);

        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'hash') { continue; }
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs);
        $computed = hash_hmac('sha256', implode("\n", $pairs), $secret);
        if (!hash_equals($computed, $hash)) {
            error_log('MaxWebApp: подпись не совпала; поля: ' . implode(',', array_keys($params)));
            return null;
        }

        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;
        $age = time() - $authDate;
        if ($authDate <= 0 || $age < -self::CLOCK_SKEW || $age > self::MAX_AUTH_AGE) {
            return null;
        }

        $userRaw = $params['user'] ?? '';
        $u = json_decode($userRaw, true);
        if (!is_array($u) || !isset($u['id'])) { return null; }

        return [
            'id'         => (string) $u['id'],
            'first_name' => isset($u['first_name']) ? (string) $u['first_name'] : null,
            'last_name'  => isset($u['last_name'])  ? (string) $u['last_name']  : null,
            'username'   => isset($u['username'])   ? (string) $u['username']   : null,
        ];
    }
}
