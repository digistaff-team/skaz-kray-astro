<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Справочник статей бюджета: приход (income) и расход (expense).
 * Статьи не удаляются физически — только архивируются (is_active=0),
 * чтобы исторические операции по статье не теряли имя.
 * Сортировка по position — в SQL (переносимо: обычный ORDER BY).
 */
final class CouncilCategoryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $kind, string $name): int
    {
        $countSt = $this->db->prepare('SELECT COUNT(*) FROM council_ledger_categories WHERE kind = ?');
        $countSt->execute([$kind]);
        $position = (int) $countSt->fetchColumn();

        $st = $this->db->prepare(
            'INSERT INTO council_ledger_categories (kind, name, position) VALUES (?, ?, ?)'
        );
        $st->execute([$kind, mb_substr($name, 0, 160), $position]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_ledger_categories WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByKind(string $kind, bool $onlyActive): array
    {
        $sql = 'SELECT * FROM council_ledger_categories WHERE kind = ?';
        if ($onlyActive) { $sql .= ' AND is_active = 1'; }
        $sql .= ' ORDER BY position ASC, id ASC';
        $st = $this->db->prepare($sql);
        $st->execute([$kind]);
        return $st->fetchAll();
    }

    public function rename(int $id, string $name): void
    {
        $st = $this->db->prepare('UPDATE council_ledger_categories SET name = ? WHERE id = ?');
        $st->execute([mb_substr($name, 0, 160), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $st = $this->db->prepare('UPDATE council_ledger_categories SET is_active = ? WHERE id = ?');
        $st->execute([$active ? 1 : 0, $id]);
    }
}
