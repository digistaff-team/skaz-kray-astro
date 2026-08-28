<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ResetRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $expiresAt): string
    {
        $token = bin2hex(random_bytes(32));
        $st = $this->db->prepare(
            'INSERT INTO password_resets (token, family_id, expires_at) VALUES (?, ?, ?)'
        );
        $st->execute([$token, $familyId, $expiresAt]);
        return $token;
    }

    /** Возвращает строку, если токен существует и ещё не истёк по переданному "сейчас". */
    public function findValid(string $token, string $now): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM password_resets WHERE token = ? AND expires_at > ?'
        );
        $st->execute([$token, $now]);
        return $st->fetch() ?: null;
    }

    public function delete(string $token): void
    {
        $st = $this->db->prepare('DELETE FROM password_resets WHERE token = ?');
        $st->execute([$token]);
    }
}
