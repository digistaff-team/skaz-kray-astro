<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Книги сервиса обмена. P2P: у каждой книги владелец-семья. Модерации нет —
 * книга сразу в каталоге (available); владелец может скрыть (hidden) или
 * пометить недоступной/повреждённой (maintenance).
 */
final class BookRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $title, string $author, string $genre, ?string $description, ?string $conditionNote, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO books (family_id, title, author, genre, description, condition_note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'available\', ?, ?)'
        );
        $st->execute([$familyId, $title, $author, $genre, $description, $conditionNote, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $author, string $genre, ?string $description, ?string $conditionNote, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE books SET title = ?, author = ?, genre = ?, description = ?, condition_note = ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([$title, $author, $genre, $description, $conditionNote, $now, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE books SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM books WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findWithOwner(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT b.*, f.name AS owner_name, f.email AS owner_email
             FROM books b JOIN families f ON f.id = b.family_id WHERE b.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Каталог: книги, кроме скрытых. Поиск — подстрока по названию/автору/жанру.
     * @return array<int,array<string,mixed>>
     */
    public function listCatalog(string $search = '', string $genre = '', string $status = ''): array
    {
        $sql = 'SELECT b.*, f.name AS owner_name
                FROM books b JOIN families f ON f.id = b.family_id
                WHERE b.status <> \'hidden\'';
        $args = [];
        if ($search !== '') {
            $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.genre LIKE ?)';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }
        if ($genre !== '') {
            $sql .= ' AND b.genre = ?';
            $args[] = $genre;
        }
        if ($status !== '' && in_array($status, ['available', 'on_loan', 'maintenance'], true)) {
            $sql .= ' AND b.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY b.status = \'available\' DESC, b.updated_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare('SELECT * FROM books WHERE family_id = ? ORDER BY updated_at DESC');
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** Уникальные жанры для фильтра/подсказок (без скрытых). @return array<int,string> */
    public function genres(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT genre FROM books WHERE status <> \'hidden\' AND genre <> \'\' ORDER BY genre'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function delete(int $id): void
    {
        // book_loans уходят каскадом FK; images чистит вызывающий.
        $st = $this->db->prepare('DELETE FROM books WHERE id = ?');
        $st->execute([$id]);
    }
}
