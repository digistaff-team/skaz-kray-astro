<?php
declare(strict_types=1);
namespace SkazResidents;

final class Auth
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function login(array $family): void
    {
        session_regenerate_id(true);
        $_SESSION['family_id'] = (int) $family['id'];
        $_SESSION['role']      = $family['role'];
        $_SESSION['family_name'] = $family['name'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function id(): ?int
    {
        return isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;
    }

    public static function isEditor(): bool
    {
        return ($_SESSION['role'] ?? '') === 'editor';
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            header('Location: /poselenie/vhod');
            exit;
        }
    }

    public static function requireEditor(): void
    {
        self::requireLogin();
        if (!self::isEditor()) {
            http_response_code(403);
            exit('Доступ только для редактора поселения.');
        }
    }
}
