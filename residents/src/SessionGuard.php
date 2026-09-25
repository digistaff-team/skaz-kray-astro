<?php
declare(strict_types=1);
namespace SkazResidents;

use PDO;

/**
 * Сверка сессии с БД на каждом запросе. Сессия живёт 30 дней и продлевается
 * визитами, поэтому без сверки блокировка, удаление аккаунта или сброс пароля
 * на уже вошедших не действовали бы вовсе.
 *
 *  - аккаунта нет или он заблокирован → выход (только из этой половины: вход
 *    жителя и вход в совет независимы, см. CouncilAuth). Именно blocked, а не
 *    «не active»: вход через Telegram/MAX пускает и pending, выкидывать их нельзя;
 *  - пароль сменён после входа (отпечаток хеша в сессии не совпал) → выход:
 *    так сброс пароля модератором закрывает чужие сессии;
 *  - роль и имя берутся из БД: повышение/понижение действует сразу.
 */
final class SessionGuard
{
    public static function check(PDO $db): void
    {
        if (isset($_SESSION['family_id'])) {
            $st = $db->prepare('SELECT status, role, name, password_hash FROM families WHERE id = ?');
            $st->execute([(int) $_SESSION['family_id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!self::valid($row, 'family_pw')) {
                Auth::forget();
            } else {
                $_SESSION['role'] = $row['role'];
                $_SESSION['family_name'] = $row['name'];
            }
        }

        if (isset($_SESSION['council_id'])) {
            $st = $db->prepare('SELECT status, role, name, password_hash FROM council_members WHERE id = ?');
            $st->execute([(int) $_SESSION['council_id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!self::valid($row, 'council_pw')) {
                CouncilAuth::logout();
            } else {
                $_SESSION['council_role'] = $row['role'];
                $_SESSION['council_name'] = $row['name'];
            }
        }
    }

    /** Отпечаток хеша пароля для сессии: сам хеш в файл сессии не кладём. */
    public static function fingerprint(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    /** @param array<string,mixed>|null $row */
    private static function valid(?array $row, string $pwKey): bool
    {
        if ($row === null || $row['status'] === 'blocked') { return false; }
        $fp = self::fingerprint((string) $row['password_hash']);
        // Сессии, открытые до появления сверки, отпечатка не имеют — ставим его сейчас.
        if (!isset($_SESSION[$pwKey])) { $_SESSION[$pwKey] = $fp; return true; }
        return hash_equals((string) $_SESSION[$pwKey], $fp);
    }
}
