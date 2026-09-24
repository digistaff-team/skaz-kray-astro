<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Совместные оптовые закупки: организатор-семья публикует закупку одного товара,
 * жители записываются своим количеством (PurchaseOrderRepository).
 *
 * Собранный объём и суммы нигде не хранятся — считаются по заявкам подзапросами.
 * Так они не могут разойтись с реальным составом участников (в отличие от
 * trips.seats_free, который приходится поддерживать руками).
 */
final class PurchaseRepository
{
    /** Стадии: сбор → заказано → привезли → завершена; из любой можно отменить. */
    public const STATUSES = ['collecting', 'ordered', 'arrived', 'done', 'cancelled'];

    /** Пока идёт сбор, участники правят свои заявки; дальше состав заморожен. */
    public const OPEN = 'collecting';

    /** Ходовые категории для подсказки в форме (плюс всё, что уже завели сами). */
    public const POPULAR_CATEGORIES = ['Продукты', 'Стройматериалы', 'Одежда и обувь', 'Семена и саженцы', 'Корма', 'Хозтовары'];

    /** Подзапросы пула: объём, число участников, оплаченный объём. */
    private const TOTALS = '
        (SELECT COALESCE(SUM(o.qty), 0) FROM purchase_orders o WHERE o.purchase_id = p.id) AS collected_qty,
        (SELECT COUNT(*) FROM purchase_orders o WHERE o.purchase_id = p.id) AS participants,
        (SELECT COALESCE(SUM(o.qty), 0) FROM purchase_orders o WHERE o.purchase_id = p.id AND o.paid_at IS NOT NULL) AS paid_qty,
        (SELECT COUNT(*) FROM purchase_orders o WHERE o.purchase_id = p.id AND o.paid_at IS NULL) AS unpaid_count';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(
        int $organizerId, string $title, ?string $category, string $unit, ?string $pricePerUnit,
        ?string $targetQty, ?string $deadline, ?string $supplier, ?string $pickup, ?string $note, string $now
    ): int {
        $st = $this->db->prepare(
            'INSERT INTO purchases (organizer_id, title, category, unit, price_per_unit, target_qty,
                                    deadline, supplier, pickup, note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . "'collecting'" . ', ?, ?)'
        );
        $st->execute([$organizerId, $title, $category, $unit, $pricePerUnit, $targetQty,
                      $deadline, $supplier, $pickup, $note, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id, string $title, ?string $category, string $unit, ?string $pricePerUnit,
        ?string $targetQty, ?string $deadline, ?string $supplier, ?string $pickup, ?string $note, string $now
    ): void {
        $st = $this->db->prepare(
            'UPDATE purchases SET title = ?, category = ?, unit = ?, price_per_unit = ?, target_qty = ?,
                                  deadline = ?, supplier = ?, pickup = ?, note = ?, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([$title, $category, $unit, $pricePerUnit, $targetQty,
                      $deadline, $supplier, $pickup, $note, $now, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM purchases WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Закупка с именем организатора и подсчитанным пулом — для карточки. */
    public function findWithTotals(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS organizer_name, f.email AS organizer_email, ' . self::TOTALS . '
             FROM purchases p
             JOIN families f ON f.id = p.organizer_id
             WHERE p.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? $this->withProgress($row) : null;
    }

    /**
     * Доска закупок. Сначала идёт сбор, затем заказанные и привезённые, в конце
     * завершённые и отменённые; внутри группы — по дедлайну и свежести.
     * @return array<int,array<string,mixed>>
     */
    public function listBoard(string $search = '', string $category = '', string $status = ''): array
    {
        $sql = 'SELECT p.*, f.name AS organizer_name, ' . self::TOTALS . '
                FROM purchases p
                JOIN families f ON f.id = p.organizer_id
                WHERE 1 = 1';
        $args = [];
        if ($search !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.category LIKE ? OR p.supplier LIKE ?)';
            $like = '%' . $search . '%';
            array_push($args, $like, $like, $like);
        }
        if ($category !== '') { $sql .= ' AND p.category = ?'; $args[] = $category; }
        if (in_array($status, self::STATUSES, true)) { $sql .= ' AND p.status = ?'; $args[] = $status; }
        $sql .= " ORDER BY CASE p.status
                      WHEN 'collecting' THEN 0 WHEN 'ordered' THEN 1 WHEN 'arrived' THEN 2
                      WHEN 'done' THEN 3 ELSE 4 END,
                  p.deadline IS NULL, p.deadline, p.id DESC";
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return array_map([$this, 'withProgress'], $st->fetchAll());
    }

    /** Закупки, которые ведёт семья. @return array<int,array<string,mixed>> */
    public function listByOrganizer(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS organizer_name, ' . self::TOTALS . '
             FROM purchases p
             JOIN families f ON f.id = p.organizer_id
             WHERE p.organizer_id = ? ORDER BY p.id DESC'
        );
        $st->execute([$familyId]);
        return array_map([$this, 'withProgress'], $st->fetchAll());
    }

    /** Закупки, где семья участвует (со своим количеством). @return array<int,array<string,mixed>> */
    public function listByParticipant(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS organizer_name, ' . self::TOTALS . ',
                    my.qty AS my_qty, my.paid_at AS my_paid_at
             FROM purchases p
             JOIN families f ON f.id = p.organizer_id
             JOIN purchase_orders my ON my.purchase_id = p.id AND my.family_id = ?
             ORDER BY p.id DESC'
        );
        $st->execute([$familyId]);
        return array_map([$this, 'withProgress'], $st->fetchAll());
    }

    /** Сколько закупок сейчас собирают пул — для плитки приложения. */
    public function countCollecting(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM purchases WHERE status = 'collecting'")->fetchColumn();
    }

    public function setStatus(int $id, string $status, string $now): void
    {
        if (!in_array($status, self::STATUSES, true)) { return; }
        $st = $this->db->prepare('UPDATE purchases SET status = ?, updated_at = ? WHERE id = ?');
        $st->execute([$status, $now, $id]);
    }

    public function delete(int $id): void
    {
        // Заявки удаляем явно, не полагаясь на каскад FK: в MySQL он есть, а в
        // SQLite (тесты) внешние ключи по умолчанию не включены — поведение
        // должно быть одинаковым. images чистит вызывающий.
        $st = $this->db->prepare('DELETE FROM purchase_orders WHERE purchase_id = ?');
        $st->execute([$id]);
        $st = $this->db->prepare('DELETE FROM purchases WHERE id = ?');
        $st->execute([$id]);
    }

    /** Категории для фильтра и формы: ходовые + уже встречающиеся. @return array<int,string> */
    public function categoriesForForm(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT category FROM purchases WHERE category IS NOT NULL AND category <> '' ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN);
        $out = self::POPULAR_CATEGORIES;
        foreach ($rows as $c) { if (!in_array((string) $c, $out, true)) { $out[] = (string) $c; } }
        return $out;
    }

    /**
     * Производные величины: суммы (объём × цена) и процент к цели. Считаем в одном
     * месте, чтобы шаблоны и уведомления не повторяли эту арифметику по-разному.
     */
    private function withProgress(array $row): array
    {
        $price     = $row['price_per_unit'] !== null ? (float) $row['price_per_unit'] : null;
        $collected = (float) ($row['collected_qty'] ?? 0);
        $target    = $row['target_qty'] !== null ? (float) $row['target_qty'] : null;

        $row['collected_qty'] = $collected;
        $row['paid_qty']      = (float) ($row['paid_qty'] ?? 0);
        $row['total_sum']     = $price !== null ? $collected * $price : null;
        $row['paid_sum']      = $price !== null ? $row['paid_qty'] * $price : null;
        $row['percent']       = ($target !== null && $target > 0) ? (int) min(100, round($collected / $target * 100)) : null;
        $row['is_open']       = $row['status'] === self::OPEN;
        return $row;
    }
}
