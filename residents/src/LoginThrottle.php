<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Ограничение неудачных попыток по произвольному ключу («область:идентификатор»)
 * поверх таблицы login_attempts. Лимит — config login_throttle.
 */
final class LoginThrottle
{
    /** Неудач с одного IP (по всем email) — шире: за одним NAT бывает полдеревни. */
    public const IP_LIMIT = ['max' => 20, 'window' => 900];

    /** @param array{max:int,window:int}|null $limit по умолчанию config login_throttle */
    public static function exceeded(string $key, ?array $limit = null): bool
    {
        $cfg = $limit ?? Config::get('login_throttle', ['max' => 5, 'window' => 900]);
        $st = Database::pdo()->prepare(
            'SELECT attempted_at FROM login_attempts WHERE email = ? ORDER BY attempted_at DESC LIMIT 50'
        );
        $st->execute([$key]);
        $cutoff = time() - (int) $cfg['window'];
        $recent = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $ts) {
            if (strtotime((string) $ts) >= $cutoff) { $recent++; }
        }
        return $recent >= (int) $cfg['max'];
    }

    /**
     * Неудачный вход: и по email, и по IP. Счётчик по IP ловит перебор одного
     * пароля по многим адресам (password spraying), который лимит на email не видит.
     */
    public static function loginBlocked(string $scope, string $email): bool
    {
        return self::exceeded($scope . $email)
            || self::exceeded($scope . 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), self::IP_LIMIT);
    }

    public static function recordLoginFailure(string $scope, string $email): void
    {
        self::record($scope . $email);
        self::record($scope . 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    public static function record(string $key): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)'
        );
        $st->execute([$key, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', date('Y-m-d H:i:s')]);
    }
}
