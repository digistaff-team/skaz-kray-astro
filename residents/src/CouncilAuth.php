<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Сессия и guard'ы раздела «Попечительский совет».
 *
 * Полностью независим от SkazResidents\Auth (раздел жителей): использует
 * собственные ключи сессии (council_id/council_role/council_name), поэтому
 * один и тот же email может быть залогинен и как семья, и как член совета,
 * а один вход не мешает другому. Хеш/проверку пароля переиспользуем из Auth.
 */
final class CouncilAuth
{
    public static function login(array $member): void
    {
        session_regenerate_id(true);
        $_SESSION['council_id']   = (int) $member['id'];
        $_SESSION['council_role'] = $member['role'];
        $_SESSION['council_name'] = $member['name'];
    }

    public static function logout(): void
    {
        // Гасим только ключи совета — вход жителя (family_*) не трогаем.
        unset($_SESSION['council_id'], $_SESSION['council_role'], $_SESSION['council_name']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['council_id']) ? (int) $_SESSION['council_id'] : null;
    }

    public static function name(): string
    {
        return (string) ($_SESSION['council_name'] ?? '');
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['council_role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            header('Location: /sovet/vhod');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Доступ только для администратора совета.');
        }
    }
}
