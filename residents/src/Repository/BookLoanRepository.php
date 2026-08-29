<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Брони/выдачи книг — жизненный цикл:
 * requested → on_loan → returned, либо requested → declined/cancelled.
 * На одну книгу допускается не более одной активной брони (requested или on_loan).
 */
final class BookLoanRepository
{
    private const ACTIVE = ['requested', 'on_loan'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $bookId, int $borrowerId, ?string $message, ?string $dueDate, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO book_loans (book_id, borrower_id, status, message, due_date, requested_at)
             VALUES (?, ?, \'requested\', ?, ?, ?)'
        );
        $st->execute([$bookId, $borrowerId, $message, $dueDate, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM book_loans WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Бронь с данными книги, владельца и читателя. */
    public function findDetailed(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT l.*, b.title AS book_title, b.family_id AS owner_id,
                    o.name AS owner_name, o.email AS owner_email,
                    br.name AS borrower_name, br.email AS borrower_email
             FROM book_loans l
             JOIN books b     ON b.id = l.book_id
             JOIN families o  ON o.id = b.family_id
             JOIN families br ON br.id = l.borrower_id
             WHERE l.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Активная (requested/on_loan) бронь книги, если есть. */
    public function activeForBook(int $bookId): ?array
    {
        $in = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $st = $this->db->prepare(
            "SELECT l.*, br.name AS borrower_name
             FROM book_loans l JOIN families br ON br.id = l.borrower_id
             WHERE l.book_id = ? AND l.status IN ($in)
             ORDER BY l.id DESC LIMIT 1"
        );
        $st->execute([$bookId, ...self::ACTIVE]);
        return $st->fetch() ?: null;
    }

    public function give(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE book_loans SET status = \'on_loan\', handed_out_at = ?, decided_at = ? WHERE id = ?'
        );
        $st->execute([$now, $now, $id]);
    }

    public function decline(int $id, string $now): void
    {
        $st = $this->db->prepare('UPDATE book_loans SET status = \'declined\', decided_at = ? WHERE id = ?');
        $st->execute([$now, $id]);
    }

    public function cancel(int $id): void
    {
        $st = $this->db->prepare('UPDATE book_loans SET status = \'cancelled\' WHERE id = ?');
        $st->execute([$id]);
    }

    public function markReturned(int $id, string $condition, ?string $note, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE book_loans SET status = \'returned\', returned_at = ?, return_condition = ?, return_note = ? WHERE id = ?'
        );
        $st->execute([$now, $condition, $note, $id]);
    }

    /**
     * Входящие брони по книгам владельца $ownerId.
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public function listIncoming(int $ownerId, array $statuses = []): array
    {
        $sql = 'SELECT l.*, b.title AS book_title, br.name AS borrower_name
                FROM book_loans l
                JOIN books b     ON b.id = l.book_id
                JOIN families br ON br.id = l.borrower_id
                WHERE b.family_id = ?';
        $args = [$ownerId];
        if ($statuses) {
            $sql .= ' AND l.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($args, ...$statuses);
        }
        $sql .= ' ORDER BY l.requested_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Брони, где семья — читатель. @return array<int,array<string,mixed>> */
    public function listByBorrower(int $borrowerId): array
    {
        $st = $this->db->prepare(
            'SELECT l.*, b.title AS book_title, o.name AS owner_name
             FROM book_loans l
             JOIN books b    ON b.id = l.book_id
             JOIN families o ON o.id = b.family_id
             WHERE l.borrower_id = ?
             ORDER BY l.requested_at DESC'
        );
        $st->execute([$borrowerId]);
        return $st->fetchAll();
    }

    /** История броней конкретной книги. @return array<int,array<string,mixed>> */
    public function historyForBook(int $bookId): array
    {
        $st = $this->db->prepare(
            'SELECT l.*, br.name AS borrower_name
             FROM book_loans l JOIN families br ON br.id = l.borrower_id
             WHERE l.book_id = ? ORDER BY l.requested_at DESC'
        );
        $st->execute([$bookId]);
        return $st->fetchAll();
    }
}
