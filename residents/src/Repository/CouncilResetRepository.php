<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/** Токены сброса пароля для членов совета (аналог ResetRepository семей). */
final class CouncilResetRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $memberId, string $expiresAt): string
    {
        $token = bin2hex(random_bytes(32));
        $st = $this->db->prepare(
            'INSERT INTO council_password_resets (token, member_id, expires_at) VALUES (?, ?, ?)'
        );
        $st->execute([$token, $memberId, $expiresAt]);
        return $token;
    }

    /** Возвращает строку, если токен существует и ещё не истёк по переданному "сейчас". */
    public function findValid(string $token, string $now): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM council_password_resets WHERE token = ? AND expires_at > ?'
        );
        $st->execute([$token, $now]);
        return $st->fetch() ?: null;
    }

    public function delete(string $token): void
    {
        $st = $this->db->prepare('DELETE FROM council_password_resets WHERE token = ?');
        $st->execute([$token]);
    }
}
