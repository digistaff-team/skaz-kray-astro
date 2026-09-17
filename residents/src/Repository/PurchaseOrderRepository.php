<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Заявки участников закупки: одна семья — одна заявка, повторная запись правит
 * прежнюю (UNIQUE purchase_id+family_id). Пока закупка в статусе «сбор», житель
 * меняет количество и выходит сам; дальше состав замораживает контроллер.
 */
final class PurchaseOrderRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Записать семью в закупку или поправить её количество. Отдельный SELECT
     * вместо ON DUPLICATE KEY / UPSERT — синтаксис у MySQL и SQLite (тесты)
     * разный, а нагрузки здесь нет.
     */
    public function place(int $purchaseId, int $familyId, string $qty, ?string $note, string $now): int
    {
        $existing = $this->findFor($purchaseId, $familyId);
        if ($existing) {
            $st = $this->db->prepare('UPDATE purchase_orders SET qty = ?, note = ?, updated_at = ? WHERE id = ?');
            $st->execute([$qty, $note, $now, (int) $existing['id']]);
            return (int) $existing['id'];
        }
        $st = $this->db->prepare(
            'INSERT INTO purchase_orders (purchase_id, family_id, qty, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$purchaseId, $familyId, $qty, $note, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    /** Заявка семьи в этой закупке (или null). */
    public function findFor(int $purchaseId, int $familyId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM purchase_orders WHERE purchase_id = ? AND family_id = ?');
        $st->execute([$purchaseId, $familyId]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Состав участников закупки с именами поместий. @return array<int,array<string,mixed>> */
    public function listFor(int $purchaseId): array
    {
        $st = $this->db->prepare(
            'SELECT o.*, f.name AS family_name, f.email AS family_email
             FROM purchase_orders o
             JOIN families f ON f.id = o.family_id
             WHERE o.purchase_id = ?
             ORDER BY o.id'
        );
        $st->execute([$purchaseId]);
        return $st->fetchAll();
    }

    /** Кого уведомлять о стадиях закупки. @return array<int,int> family_id */
    public function participantIds(int $purchaseId): array
    {
        $st = $this->db->prepare('SELECT family_id FROM purchase_orders WHERE purchase_id = ?');
        $st->execute([$purchaseId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function remove(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM purchase_orders WHERE id = ?');
        $st->execute([$id]);
    }

    /** Отметить/снять оплату (отмечает организатор; сами переводы — вне портала). */
    public function setPaid(int $id, bool $paid, string $now): void
    {
        $st = $this->db->prepare('UPDATE purchase_orders SET paid_at = ?, updated_at = ? WHERE id = ?');
        $st->execute([$paid ? $now : null, $now, $id]);
    }
}
