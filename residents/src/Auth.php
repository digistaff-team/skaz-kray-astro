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

    /**
     * Как открыт портал в этой сессии: tg (Mini App Telegram), max (мини-приложение
     * MAX) или web (браузер, вход по паролю). Нужно клиенту: SDK мессенджера имеет
     * смысл тянуть только «своему», а запрос к чужому — это в лучшем случае
     * лишний коннект, в худшем — ожидание до системного таймаута (telegram.org у
     * части операторов не отказывает, а молчит). См. assets/tg-webapp.js.
     */
    public static function setPlatform(string $platform): void
    {
        $_SESSION['platform'] = in_array($platform, ['tg', 'max', 'web'], true) ? $platform : 'web';
    }

    /** tg|max|web, либо '' — платформа неизвестна (сессия от прошлых версий). */
    public static function platform(): string
    {
        return (string) ($_SESSION['platform'] ?? '');
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

    public static function name(): string
    {
        return (string) ($_SESSION['family_name'] ?? '');
    }

    /** Админ сайта — надмножество редактора: может всё, что редактор, плюс управление сайтом. */
    public static function isEditor(): bool
    {
        $role = $_SESSION['role'] ?? '';
        return $role === 'editor' || $role === 'admin';
    }

    /** Админ сайта (управляет разделами и т.п.). Редактор текста админом не является. */
    public static function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    /**
     * Может ли текущий модератор блокировать аккаунт или сбрасывать ему пароль.
     * Редактор управляет только жителями: иначе, сбросив пароль админа (новый
     * пароль показывается модератору), он сам стал бы админом.
     */
    public static function canManageAccount(array $target): bool
    {
        if (self::isAdmin()) { return true; }
        return self::isEditor() && !in_array($target['role'] ?? '', ['editor', 'admin'], true);
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            // Запоминаем, куда житель шёл: после повторного входа через мессенджер
            // его вернут туда же (auth/login.php). Только для GET — отправку формы
            // после входа не повторить.
            $next = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
                ? self::safeNext((string) ($_SERVER['REQUEST_URI'] ?? ''))
                : null;
            header('Location: /poselenie/vhod' . ($next !== null ? '?next=' . rawurlencode($next) : ''));
            exit;
        }
    }

    /**
     * Адрес возврата после входа — только внутренний путь портала жителей.
     * Всё прочее (чужой хост, «//evil», схема, выход из /poselenie/) — null:
     * иначе ?next= стал бы открытым редиректом.
     */
    public static function safeNext(string $uri): ?string
    {
        if (!str_starts_with($uri, '/poselenie/') || str_contains($uri, '\\')) { return null; }
        if (preg_match('~[\x00-\x1F]~', $uri)) { return null; }   // переводы строк и прочие управляющие
        return $uri;
    }

    public static function requireEditor(): void
    {
        self::requireLogin();
        if (!self::isEditor()) {
            http_response_code(403);
            exit('Доступ только для редактора поселения.');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            exit('Доступ только для администратора сайта.');
        }
    }
}
