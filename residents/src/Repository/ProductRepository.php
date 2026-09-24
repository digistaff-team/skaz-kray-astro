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

    /**
     * Видимость: residents («только соседи» — внутрипоселенческий рынок, публикуется
     * сразу) | public («на сайте», в разделе Ярмарка — на проверку редактору).
     */
    public function create(int $familyId, string $title, string $description, ?string $price, string $contact, string $now, string $visibility = 'public', ?string $unit = null): int
    {
        $status      = $visibility === 'public' ? 'pending' : 'published';
        $publishedAt = $visibility === 'public' ? null : $now;
        $st = $this->db->prepare(
            'INSERT INTO products (family_id, title, description, price, unit, contact, visibility, status, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$familyId, $title, $description, $price, $unit, $contact, $visibility, $status, $publishedAt, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $description, ?string $price, string $contact, string $now, string $visibility = 'public', ?string $unit = null): void
    {
        if ($visibility === 'public') {
            $st = $this->db->prepare(
                'UPDATE products
                 SET title = ?, description = ?, price = ?, unit = ?, contact = ?, visibility = ?,
                     status = \'pending\', reject_reason = NULL, published_at = NULL, updated_at = ?
                 WHERE id = ?'
            );
            $st->execute([$title, $description, $price, $unit, $contact, $visibility, $now, $id]);
        } else {
            $st = $this->db->prepare(
                'UPDATE products
                 SET title = ?, description = ?, price = ?, unit = ?, contact = ?, visibility = ?,
                     status = \'published\', reject_reason = NULL, published_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $st->execute([$title, $description, $price, $unit, $contact, $visibility, $now, $now, $id]);
        }
    }

    /**
     * Лента внутрипоселенческого рынка — ВСЕ опубликованные товары (и residents, и public).
     * @return array<int,array<string,mixed>>
     */
    public function listAvailable(int $limit, int $offset): array
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
             WHERE p.status = \'published\' AND p.visibility = \'public\'
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
            'SELECT COUNT(*) FROM products WHERE status = \'published\' AND visibility = \'public\''
        )->fetchColumn();
    }

    public function findPublishedById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.id = ? AND p.status = \'published\' AND p.visibility = \'public\''
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

    /**
     * Отклонённые модератором объявления семьи — ровно то, что нужно «Моим делам»,
     * без описаний: список дел считается на каждой странице.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listRejectedByFamily(int $familyId): array
    {
        $st = $this->db->prepare(
            "SELECT id, title, reject_reason, updated_at FROM products
             WHERE family_id = ? AND status = 'rejected' ORDER BY updated_at ASC"
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /**
     * Товар с тем же названием, созданный этой же семьёй не раньше $since — след повторной
     * отправки формы (телефон не дождался ответа, житель нажал «Разместить» ещё раз).
     */
    public function findRecentByTitle(int $familyId, string $title, string $since): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM products
             WHERE family_id = ? AND title = ? AND created_at >= ?
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$familyId, $title, $since]);
        return $st->fetch() ?: null;
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
