<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Аккаунты членов Попечительского совета. Отдельная таблица council_members —
 * email независим от families (см. council-schema.sql). Приглашение-only:
 * аккаунты заводит администратор, поэтому создаются сразу active.
 */
final class CouncilMemberRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $email, string $passwordHash, string $name, string $role = 'member'): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_members (email, password_hash, name, status, role)
             VALUES (?, ?, ?, \'active\', ?)'
        );
        $st->execute([$email, $passwordHash, $name, $role]);
        return (int) $this->db->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = $this->db->prepare('UPDATE council_members SET password_hash = ? WHERE id = ?');
        $st->execute([$passwordHash, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE council_members SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->query(
            'SELECT * FROM council_members ORDER BY created_at ASC'
        )->fetchAll();
    }
}
