<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ImageRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function add(string $ownerType, int $ownerId, string $path, int $sort): int
    {
        $st = $this->db->prepare(
            'INSERT INTO images (owner_type, owner_id, path, sort) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$ownerType, $ownerId, $path, $sort]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function listFor(string $ownerType, int $ownerId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM images WHERE owner_type = ? AND owner_id = ? ORDER BY sort ASC, id ASC'
        );
        $st->execute([$ownerType, $ownerId]);
        return $st->fetchAll();
    }

    public function deleteFor(string $ownerType, int $ownerId): void
    {
        $st = $this->db->prepare('DELETE FROM images WHERE owner_type = ? AND owner_id = ?');
        $st->execute([$ownerType, $ownerId]);
    }

    public function deleteById(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM images WHERE id = ?');
        $st->execute([$id]);
    }
}
