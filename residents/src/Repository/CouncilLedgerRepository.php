<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Операции бюджета (приход/расход). Все агрегации/группировки по месяцам
 * считаются в PHP из одного запроса allWithCategory() — по правилу раздела
 * (никаких диалектных функций дат/агрегатов в SQL). Месяц = substr(entry_date,0,7).
 */
final class CouncilLedgerRepository
{
    private const ALLOWED = ['kind', 'category_id', 'amount', 'entry_date', 'note'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $kind, int $categoryId, float $amount, string $entryDate, string $note, string $author): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_ledger_entries (kind, category_id, amount, entry_date, note, author)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$kind, $categoryId, $amount, $entryDate, mb_substr($note, 0, 300), mb_substr($author, 0, 160)]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_ledger_entries WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** $patch — подмножество ALLOWED. */
    public function updateFields(int $id, array $patch): void
    {
        $set = [];
        $args = [];
        foreach ($patch as $key => $val) {
            if (!in_array($key, self::ALLOWED, true)) { continue; }
            if ($key === 'amount')   { $val = max(0.0, (float) $val); }
            if ($key === 'note')     { $val = mb_substr((string) $val, 0, 300); }
            $set[] = "$key = ?";
            $args[] = $val;
        }
        if (!$set) { return; }
        $args[] = $id;
        $st = $this->db->prepare('UPDATE council_ledger_entries SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($args);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM council_ledger_entries WHERE id = ?')->execute([$id]);
    }

    /**
     * Все операции с именем статьи, новые сверху. amount приведён к float.
     * @return array<int,array<string,mixed>>
     */
    public function allWithCategory(): array
    {
        $rows = $this->db->query(
            'SELECT e.id, e.kind, e.category_id, e.amount, e.entry_date, e.note, e.author, e.created_at,
                    c.name AS category_name
             FROM council_ledger_entries e
             JOIN council_ledger_categories c ON c.id = e.category_id
             ORDER BY e.entry_date DESC, e.id DESC'
        )->fetchAll();
        foreach ($rows as &$r) { $r['amount'] = (float) $r['amount']; }
        unset($r);
        return $rows;
    }

    /** Операции одного месяца (YYYY-MM), новые сверху. @return array<int,array<string,mixed>> */
    public function listForMonth(string $ym): array
    {
        return array_values(array_filter(
            $this->allWithCategory(),
            static fn($r) => substr((string) $r['entry_date'], 0, 7) === $ym
        ));
    }

    /**
     * Суммы прихода/расхода по месяцам.
     * @return array<string,array{income:float,expense:float}>
     */
    public function sumsByMonth(): array
    {
        $out = [];
        foreach ($this->allWithCategory() as $r) {
            $ym = substr((string) $r['entry_date'], 0, 7);
            if (!isset($out[$ym])) { $out[$ym] = ['income' => 0.0, 'expense' => 0.0]; }
            $out[$ym][$r['kind'] === 'income' ? 'income' : 'expense'] += (float) $r['amount'];
        }
        return $out;
    }

    /** Список месяцев с данными, новые сверху. @return array<int,string> */
    public function monthsWithData(): array
    {
        $seen = [];
        foreach ($this->allWithCategory() as $r) { // уже отсортировано date desc
            $ym = substr((string) $r['entry_date'], 0, 7);
            $seen[$ym] = true;
        }
        return array_keys($seen);
    }

    /**
     * Разбивка расходов месяца по статьям, по убыванию суммы.
     * @return array<int,array{name:string,sum:float}>
     */
    public function expenseByCategory(string $ym): array
    {
        $acc = [];
        foreach ($this->listForMonth($ym) as $r) {
            if ($r['kind'] !== 'expense') { continue; }
            $name = (string) $r['category_name'];
            $acc[$name] = ($acc[$name] ?? 0.0) + (float) $r['amount'];
        }
        $rows = [];
        foreach ($acc as $name => $sum) { $rows[] = ['name' => $name, 'sum' => $sum]; }
        usort($rows, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        return $rows;
    }
}
