<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Пункты повестки текущей встречи Совета. Добавляют сами члены совета; дежурный
 * председатель/админ сортируют, удаляют и отмечают «обсуждено». При авто-переносе
 * встречи обсуждённые удаляются, необсуждённые остаются (clearDiscussed()).
 *
 * Два постоянных пункта (is_fixed=1) — «Изменения в составе Совета» и
 * «Статус по текущим задачам» — всегда в начале списка: их нельзя удалить
 * или переместить, при переносе встречи они не исчезают и сбрасываются
 * в необсуждённое (ensureFixed()).
 */
final class CouncilAgendaRepository
{
    /** Постоянные пункты: название и отрицательный sort (всегда выше обычных, sort >= 0). */
    private const FIXED = [
        ['title' => 'Изменения в составе Совета', 'sort' => -2],
        ['title' => 'Статус по текущим задачам', 'sort' => -1],
    ];

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

    /**
     * Гарантировать наличие двух постоянных пунктов в начале повестки.
     * Идемпотентно: отсутствующие создаёт, сбитый порядок возвращает на место,
     * упавшие в «обсуждено» — сбрасывает. Вызывается при рендере страниц.
     */
    public function ensureFixed(): void
    {
        foreach (self::FIXED as $i => $f) {
            $st = $this->db->prepare('SELECT id, sort, discussed FROM council_agenda_items WHERE is_fixed = 1 AND title = ?');
            $st->execute([$f['title']]);
            $row = $st->fetch();
            if (!$row) {
                $ins = $this->db->prepare('INSERT INTO council_agenda_items (title, author, sort, is_fixed) VALUES (?, \'\', ?, 1)');
                $ins->execute([$f['title'], $f['sort']]);
                continue;
            }
            if ((int) $row['sort'] !== $f['sort'] || (int) $row['discussed'] !== 0) {
                $up = $this->db->prepare('UPDATE council_agenda_items SET sort = ?, discussed = 0 WHERE id = ?');
                $up->execute([$f['sort'], (int) $row['id']]);
            }
        }
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_agenda_items WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function add(string $title, string $author): int
    {
        // Обычные пункты — sort >= 0, чтобы постоянные (sort < 0) оставались первыми.
        $sort = (int) $this->db->query('SELECT COALESCE(MAX(sort)+1, 0) FROM council_agenda_items')->fetchColumn();
        if ($sort < 0) { $sort = 0; }
        $st = $this->db->prepare('INSERT INTO council_agenda_items (title, author, sort) VALUES (?, ?, ?)');
        $st->execute([$title, $author, $sort]);
        return (int) $this->db->lastInsertId();
    }

    /** Постоянные пункты удалять нельзя. @return bool удалён ли пункт */
    public function delete(int $id): bool
    {
        $st = $this->db->prepare('DELETE FROM council_agenda_items WHERE id = ? AND is_fixed = 0');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    public function toggleDiscussed(int $id): void
    {
        $this->db->prepare('UPDATE council_agenda_items SET discussed = 1 - discussed WHERE id = ?')->execute([$id]);
    }

    /** Поменять порядок с соседом: dir = -1 (вверх) | +1 (вниз). Постоянные не двигаются. */
    public function move(int $id, int $dir): void
    {
        $cur = $this->findById($id);
        if (!$cur || (int) $cur['is_fixed'] === 1) { return; }
        $cmp = $dir < 0 ? '<' : '>';
        $ord = $dir < 0 ? 'DESC' : 'ASC';
        $st = $this->db->prepare(
            "SELECT * FROM council_agenda_items WHERE (sort {$cmp} ? OR (sort = ? AND id {$cmp} ?))
             AND is_fixed = 0
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

    /**
     * При переносе встречи: обсуждённые удалить, необсуждённые оставить и
     * пометить как перенесённые с прошлой встречи (carried_over = 1).
     * Постоянные пункты (is_fixed=1) переживают перенос без пометок,
     * их отметка «обсуждено» сбрасывается — ensureFixed() при рендере
     * вернёт их в начало списка.
     */
    public function rollOver(): void
    {
        $this->db->exec('DELETE FROM council_agenda_items WHERE discussed = 1 AND is_fixed = 0');
        $this->db->exec('UPDATE council_agenda_items SET carried_over = 1 WHERE is_fixed = 0');
        $this->db->exec('UPDATE council_agenda_items SET discussed = 0 WHERE is_fixed = 1');
    }
}