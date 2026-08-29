<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Инструменты сервиса шеринга. P2P: у каждого инструмента владелец-семья.
 * Модерации нет — инструмент сразу в каталоге (статус available), владелец
 * может скрыть (hidden) или пометить обслуживание (maintenance).
 */
final class ToolRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $name, string $category, ?string $description, ?string $conditionNote, ?string $terms, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO tools (family_id, name, category, description, condition_note, terms, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'available\', ?, ?)'
        );
        $st->execute([$familyId, $name, $category, $description, $conditionNote, $terms, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $category, ?string $description, ?string $conditionNote, ?string $terms, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE tools SET name = ?, category = ?, description = ?, condition_note = ?, terms = ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([$name, $category, $description, $conditionNote, $terms, $now, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE tools SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tools WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findWithOwner(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT t.*, f.name AS owner_name, f.email AS owner_email
             FROM tools t JOIN families f ON f.id = t.family_id WHERE t.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Каталог: инструменты, кроме скрытых. Фильтры — подстрока по названию/категории,
     * конкретная категория, конкретный статус.
     * @return array<int,array<string,mixed>>
     */
    public function listCatalog(string $search = '', string $category = '', string $status = ''): array
    {
        $sql = 'SELECT t.*, f.name AS owner_name
                FROM tools t JOIN families f ON f.id = t.family_id
                WHERE t.status <> \'hidden\'';
        $args = [];
        if ($search !== '') {
            $sql .= ' AND (t.name LIKE ? OR t.category LIKE ?)';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }
        if ($category !== '') {
            $sql .= ' AND t.category = ?';
            $args[] = $category;
        }
        if ($status !== '' && in_array($status, ['available', 'on_loan', 'maintenance'], true)) {
            $sql .= ' AND t.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY t.status = \'available\' DESC, t.updated_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare('SELECT * FROM tools WHERE family_id = ? ORDER BY updated_at DESC');
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** Уникальные категории для фильтра/подсказок (без скрытых). @return array<int,string> */
    public function categories(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT category FROM tools WHERE status <> \'hidden\' AND category <> \'\' ORDER BY category'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function delete(int $id): void
    {
        // tool_loans и images чистятся вызывающим (images) / каскадом FK (loans).
        $st = $this->db->prepare('DELETE FROM tools WHERE id = ?');
        $st->execute([$id]);
    }
}
