<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class FamilyRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function createPending(string $email, string $passwordHash, string $name): int
    {
        $st = $this->db->prepare(
            'INSERT INTO families (email, password_hash, name, status, role)
             VALUES (?, ?, ?, \'pending\', \'resident\')'
        );
        $st->execute([$email, $passwordHash, $name]);
        return (int) $this->db->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE families SET status = \'active\', approved_at = ? WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE families SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = $this->db->prepare('UPDATE families SET password_hash = ? WHERE id = ?');
        $st->execute([$passwordHash, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listByStatus(string $status): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM families WHERE status = ? ORDER BY created_at ASC'
        );
        $st->execute([$status]);
        return $st->fetchAll();
    }
}
