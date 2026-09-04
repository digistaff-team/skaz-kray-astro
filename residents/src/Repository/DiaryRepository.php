<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class DiaryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Видимость: private («Только я» — только автор в своём дневнике) |
     * residents («Только соседи» — сразу в ленте дневников жителей) |
     * public («Все на сайте» — на проверку редактору, затем на внешний сайт).
     * На модерацию идёт ТОЛЬКО public; private и residents публикуются сразу.
     * is_public держим синхронно (=1 только для public), чтобы внешняя лента не менялась.
     */
    public function create(int $familyId, string $title, string $body, string $visibility, string $now): int
    {
        $isPublic    = $visibility === 'public' ? 1 : 0;
        $status      = $visibility === 'public' ? 'pending' : 'published';
        $publishedAt = $visibility === 'public' ? null : $now;
        $st = $this->db->prepare(
            'INSERT INTO diary_entries (family_id, title, body, is_public, visibility, status, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$familyId, $title, $body, $isPublic, $visibility, $status, $publishedAt, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $body, string $visibility, string $now): void
    {
        $isPublic = $visibility === 'public' ? 1 : 0;
        if ($visibility === 'public') {
            // На проверку редактору.
            $st = $this->db->prepare(
                'UPDATE diary_entries
                 SET title = ?, body = ?, is_public = ?, visibility = ?, status = \'pending\',
                     reject_reason = NULL, published_at = NULL, updated_at = ?
                 WHERE id = ?'
            );
            $st->execute([$title, $body, $isPublic, $visibility, $now, $id]);
        } else {
            // private / residents — публикуется сразу.
            $st = $this->db->prepare(
                'UPDATE diary_entries
                 SET title = ?, body = ?, is_public = ?, visibility = ?, status = \'published\',
                     reject_reason = NULL, published_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $st->execute([$title, $body, $isPublic, $visibility, $now, $now, $id]);
        }
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE diary_entries
             SET status = \'published\', published_at = ?, reject_reason = NULL
             WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function reject(int $id, string $reason): void
    {
        $st = $this->db->prepare(
            'UPDATE diary_entries SET status = \'rejected\', reject_reason = ? WHERE id = ?'
        );
        $st->execute([$reason, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM diary_entries WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPublished(int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.status = \'published\' AND d.visibility <> \'private\'
             ORDER BY d.published_at DESC
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
            'SELECT COUNT(*) FROM diary_entries WHERE status = \'published\' AND visibility <> \'private\''
        )->fetchColumn();
    }

    public function findPublishedById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.id = ? AND d.status = \'published\' AND d.visibility <> \'private\''
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Для ВНЕШНЕГО публичного сайта (/dnevniki-pomestiy/, без авторизации):
     * только опубликованные записи, отмеченные семьёй галочкой «на внешний сайт».
     * @return array<int,array<string,mixed>>
     */
    public function listPublishedPublic(int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.status = \'published\' AND d.is_public = 1
             ORDER BY d.published_at DESC
             LIMIT ? OFFSET ?'
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->bindValue(2, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function countPublishedPublic(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM diary_entries WHERE status = \'published\' AND is_public = 1'
        )->fetchColumn();
    }

    public function findPublishedPublicById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.id = ? AND d.status = \'published\' AND d.is_public = 1'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM diary_entries WHERE family_id = ? ORDER BY updated_at DESC'
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> Все ожидающие модерации, с именем семьи. */
    public function listPending(): array
    {
        return $this->db->query(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.status = \'pending\' ORDER BY d.created_at ASC'
        )->fetchAll();
    }

    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM diary_entries WHERE id = ?');
        $st->execute([$id]);
    }
}
