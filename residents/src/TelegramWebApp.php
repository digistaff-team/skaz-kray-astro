<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Проверка Telegram Mini App initData (Web Apps). Порт
 * abconsult-app/src/lib/telegram-webapp.ts в PHP.
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 *
 * Секрет = HMAC_SHA256(key="WebAppData", msg=botToken); подпись = HMAC_SHA256(
 * key=secret, msg=data_check_string) — все поля кроме hash, url-decoded,
 * отсортированные, «k=v» через \n. Поле user — URL-decoded JSON.
 */
final class TelegramWebApp
{
    private const MAX_AUTH_AGE = 86400;   // 24 часа
    private const CLOCK_SKEW   = 300;     // допуск на рассинхрон часов, 5 мин

    /**
     * Возвращает данные пользователя (['id','first_name','last_name','username'])
     * при валидной подписи и свежем auth_date, иначе null.
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

        // Каноничный вариант исключает только hash (оставляя signature); часть
        // клиентов считает без signature — принимаем обе трактовки.
        if (!self::hashMatches($params, $secret, $hash, false)
            && !self::hashMatches($params, $secret, $hash, true)) {
            error_log('TelegramWebApp: подпись не совпала; поля: ' . implode(',', array_keys($params)));
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

    /** @param array<string,string> $params */
    private static function hashMatches(array $params, string $secret, string $hash, bool $excludeSignature): bool
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'hash') { continue; }
            if ($excludeSignature && $k === 'signature') { continue; }
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs);
        $computed = hash_hmac('sha256', implode("\n", $pairs), $secret);
        return hash_equals($computed, $hash);
    }
}
