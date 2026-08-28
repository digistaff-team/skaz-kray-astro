<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ProductRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $title, string $description, ?string $price, string $contact, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO products (family_id, title, description, price, contact, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?)'
        );
        $st->execute([$familyId, $title, $description, $price, $contact, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $description, ?string $price, string $contact, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE products
             SET title = ?, description = ?, price = ?, contact = ?,
                 status = \'pending\', reject_reason = NULL, published_at = NULL, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([$title, $description, $price, $contact, $now, $id]);
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE products SET status = \'published\', published_at = ?, reject_reason = NULL WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function reject(int $id, string $reason): void
    {
        $st = $this->db->prepare(
            'UPDATE products SET status = \'rejected\', reject_reason = ? WHERE id = ?'
        );
        $st->execute([$reason, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPublished(int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.status = \'published\'
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?'
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->bindValue(2, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function countPublished(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM products WHERE status = \'published\''
        )->fetchColumn();
    }

    public function findPublishedById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.id = ? AND p.status = \'published\''
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM products WHERE family_id = ? ORDER BY updated_at DESC'
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listPending(): array
    {
        return $this->db->query(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.status = \'pending\' ORDER BY p.created_at ASC'
        )->fetchAll();
    }

    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $st->execute([$id]);
    }
}
