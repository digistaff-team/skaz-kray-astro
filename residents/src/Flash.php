<?php
declare(strict_types=1);
namespace SkazResidents;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** Есть ли уже сообщение этого типа — не забирая его. */
    public static function has(string $type): bool
    {
        foreach ($_SESSION['flash'] ?? [] as $f) {
            if (($f['type'] ?? '') === $type) { return true; }
        }
        return false;
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function take(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
