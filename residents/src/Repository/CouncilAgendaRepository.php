<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Пункты повестки текущей встречи Совета. Добавляют сами члены совета; дежурный
 * председатель/админ сортируют, удаляют и отмечают «обсуждено». При авто-переносе
 * встречи обсуждённые удаляются, необсуждённые остаются (clearDiscussed()).
 */
final class CouncilAgendaRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM council_agenda_items ORDER BY sort, id')->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_agenda_items WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function add(string $title, string $author): int
    {
        $sort = (int) $this->db->query('SELECT COALESCE(MAX(sort)+1, 0) FROM council_agenda_items')->fetchColumn();
        $st = $this->db->prepare('INSERT INTO council_agenda_items (title, author, sort) VALUES (?, ?, ?)');
        $st->execute([$title, $author, $sort]);
        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM council_agenda_items WHERE id = ?')->execute([$id]);
    }

    public function toggleDiscussed(int $id): void
    {
        $this->db->prepare('UPDATE council_agenda_items SET discussed = 1 - discussed WHERE id = ?')->execute([$id]);
    }

    /** Поменять порядок с соседом: dir = -1 (вверх) | +1 (вниз). */
    public function move(int $id, int $dir): void
    {
        $cur = $this->findById($id);
        if (!$cur) { return; }
        $cmp = $dir < 0 ? '<' : '>';
        $ord = $dir < 0 ? 'DESC' : 'ASC';
        $st = $this->db->prepare(
            "SELECT * FROM council_agenda_items WHERE (sort {$cmp} ? OR (sort = ? AND id {$cmp} ?))
             ORDER BY sort {$ord}, id {$ord} LIMIT 1"
        );
        $st->execute([(int) $cur['sort'], (int) $cur['sort'], $id]);
        $neighbor = $st->fetch();
        if (!$neighbor) { return; }
        // Меняем sort местами (при равных sort — используем id-порядок через временный сдвиг).
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare('UPDATE council_agenda_items SET sort = ? WHERE id = ?');
            $u->execute([(int) $neighbor['sort'], $id]);
            $u->execute([(int) $cur['sort'], (int) $neighbor['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** При переносе встречи: обсуждённые удалить, необсуждённые оставить. */
    public function clearDiscussed(): void
    {
        $this->db->exec('DELETE FROM council_agenda_items WHERE discussed = 1');
    }
}
