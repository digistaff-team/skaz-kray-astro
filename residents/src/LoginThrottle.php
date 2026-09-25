<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Ограничение неудачных попыток по произвольному ключу («область:идентификатор»)
 * поверх таблицы login_attempts. Лимит — config login_throttle.
 */
final class LoginThrottle
{
    public static function exceeded(string $key): bool
    {
        $cfg = Config::get('login_throttle', ['max' => 5, 'window' => 900]);
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

    public static function record(string $key): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)'
        );
        $st->execute([$key, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', date('Y-m-d H:i:s')]);
    }
}
