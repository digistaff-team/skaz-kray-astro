<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Займы инструментов — жизненный цикл заявки:
 * requested → on_loan → returned, либо requested → declined/cancelled.
 * На один инструмент допускается не более одного активного займа
 * (requested или on_loan) — проверяется через activeForTool().
 */
final class ToolLoanRepository
{
    private const ACTIVE = ['requested', 'on_loan'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $toolId, int $borrowerId, ?string $message, ?string $dueDate, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO tool_loans (tool_id, borrower_id, status, message, due_date, requested_at)
             VALUES (?, ?, \'requested\', ?, ?, ?)'
        );
        $st->execute([$toolId, $borrowerId, $message, $dueDate, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tool_loans WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Займ с данными инструмента, владельца и заёмщика. */
    public function findDetailed(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT l.*, t.name AS tool_name, t.family_id AS owner_id,
                    o.name AS owner_name, o.email AS owner_email,
                    b.name AS borrower_name, b.email AS borrower_email
             FROM tool_loans l
             JOIN tools t    ON t.id = l.tool_id
             JOIN families o ON o.id = t.family_id
             JOIN families b ON b.id = l.borrower_id
             WHERE l.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Активный (requested/on_loan) займ инструмента, если есть. */
    public function activeForTool(int $toolId): ?array
    {
        $in = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $st = $this->db->prepare(
            "SELECT l.*, b.name AS borrower_name
             FROM tool_loans l JOIN families b ON b.id = l.borrower_id
             WHERE l.tool_id = ? AND l.status IN ($in)
             ORDER BY l.id DESC LIMIT 1"
        );
        $st->execute([$toolId, ...self::ACTIVE]);
        return $st->fetch() ?: null;
    }

    public function give(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE tool_loans SET status = \'on_loan\', handed_out_at = ?, decided_at = ? WHERE id = ?'
        );
        $st->execute([$now, $now, $id]);
    }

    public function decline(int $id, string $now): void
    {
        $st = $this->db->prepare('UPDATE tool_loans SET status = \'declined\', decided_at = ? WHERE id = ?');
        $st->execute([$now, $id]);
    }

    public function cancel(int $id): void
    {
        $st = $this->db->prepare('UPDATE tool_loans SET status = \'cancelled\' WHERE id = ?');
        $st->execute([$id]);
    }

    public function markReturned(int $id, string $condition, ?string $note, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE tool_loans SET status = \'returned\', returned_at = ?, return_condition = ?, return_note = ? WHERE id = ?'
        );
        $st->execute([$now, $condition, $note, $id]);
    }

    /**
     * Входящие займы по инструментам владельца $ownerId.
     * @param array<int,string> $statuses фильтр статусов (пусто = все)
     * @return array<int,array<string,mixed>>
     */
    public function listIncoming(int $ownerId, array $statuses = []): array
    {
        $sql = 'SELECT l.*, t.name AS tool_name, b.name AS borrower_name
                FROM tool_loans l
                JOIN tools t    ON t.id = l.tool_id
                JOIN families b ON b.id = l.borrower_id
                WHERE t.family_id = ?';
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

    /** Займы, где семья — заёмщик. @return array<int,array<string,mixed>> */
    public function listByBorrower(int $borrowerId): array
    {
        $st = $this->db->prepare(
            'SELECT l.*, t.name AS tool_name, o.name AS owner_name
             FROM tool_loans l
             JOIN tools t    ON t.id = l.tool_id
             JOIN families o ON o.id = t.family_id
             WHERE l.borrower_id = ?
             ORDER BY l.requested_at DESC'
        );
        $st->execute([$borrowerId]);
        return $st->fetchAll();
    }

    /** История займов конкретного инструмента. @return array<int,array<string,mixed>> */
    public function historyForTool(int $toolId): array
    {
        $st = $this->db->prepare(
            'SELECT l.*, b.name AS borrower_name
             FROM tool_loans l JOIN families b ON b.id = l.borrower_id
             WHERE l.tool_id = ? ORDER BY l.requested_at DESC'
        );
        $st->execute([$toolId]);
        return $st->fetchAll();
    }
}
