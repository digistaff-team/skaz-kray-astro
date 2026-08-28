<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Доска текущих задач совета. Задачи + подзадачи, статусы новая|в работе|выполнена,
 * приоритеты низкая|средняя|высокая, прогресс 0..100, учёт затрат, контакты, ссылки.
 * Сортировка делается в PHP (переносимо между MariaDB и SQLite).
 */
final class CouncilTaskRepository
{
    private const ALLOWED = ['title','description','author','assignee','priority','status','progress','spent','contacts','links'];
    private const PRIORITY_RANK = ['высокая' => 0, 'средняя' => 1, 'низкая' => 2];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $title, ?string $description, string $author, string $assignee, string $priority): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_tasks (title, description, author, assignee, priority)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$title, $description, $author, $assignee, $priority]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_tasks WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Выборочное обновление полей задачи. $patch — подмножество ALLOWED.
     * completed_at ставится только при ПЕРВОМ переходе в «выполнена» и сбрасывается
     * при любом другом статусе (как в эталоне).
     */
    public function updateFields(int $id, array $patch, string $now): void
    {
        $current = $this->find($id);
        if (!$current) { return; }

        $set = [];
        $args = [];
        foreach ($patch as $key => $val) {
            if (!in_array($key, self::ALLOWED, true)) { continue; }
            if ($key === 'progress') {
                $val = max(0, min(100, (int) $val));
            }
            $set[] = "$key = ?";
            $args[] = $val;
        }

        if (array_key_exists('status', $patch)) {
            if ($patch['status'] === 'выполнена') {
                if (empty($current['completed_at'])) {
                    $set[] = 'completed_at = ?';
                    $args[] = $now;
                }
            } else {
                $set[] = 'completed_at = NULL';
            }
        }

        if (!$set) { return; }
        $args[] = $id;
        $st = $this->db->prepare('UPDATE council_tasks SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($args);
    }

    public function delete(int $id): void
    {
        // Подзадачи уходят каскадом по FK (MariaDB). Для SQLite-тестов без FK чистим явно.
        $this->db->prepare('DELETE FROM council_subtasks WHERE task_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM council_tasks WHERE id = ?')->execute([$id]);
    }

    /**
     * Задачи с прикреплёнными подзадачами. $archived=false → активные (status != выполнена),
     * true → архив (выполненные). $sort ∈ priority|created|progress|spent.
     * @return array<int,array<string,mixed>>
     */
    public function listWithSubtasks(bool $archived, string $sort): array
    {
        $op = $archived ? '=' : '!=';
        $st = $this->db->prepare("SELECT * FROM council_tasks WHERE status $op 'выполнена'");
        $st->execute();
        $tasks = $st->fetchAll();
        if (!$tasks) { return []; }

        $subs = $this->subtasksByTask(array_map(static fn($t) => (int) $t['id'], $tasks));
        foreach ($tasks as &$t) {
            $t['subtasks']    = $subs[(int) $t['id']] ?? [];
            $t['total_count'] = count($t['subtasks']);
            $t['done_count']  = count(array_filter($t['subtasks'], static fn($s) => (int) $s['done'] === 1));
            $t['spent']       = (float) $t['spent'];
            $t['progress']    = (int) $t['progress'];
        }
        unset($t);

        return $this->sort($tasks, $sort);
    }

    /** @param array<int,array<string,mixed>> $tasks */
    private function sort(array $tasks, string $sort): array
    {
        usort($tasks, function ($a, $b) use ($sort) {
            return match ($sort) {
                'created'  => strcmp((string) $b['created_at'], (string) $a['created_at']),
                'progress' => $b['progress'] <=> $a['progress'],
                'spent'    => $b['spent'] <=> $a['spent'],
                default    => (self::PRIORITY_RANK[$a['priority']] ?? 1) <=> (self::PRIORITY_RANK[$b['priority']] ?? 1),
            };
        });
        return $tasks;
    }

    /** @param array<int,int> $taskIds  @return array<int,array<int,array<string,mixed>>> */
    private function subtasksByTask(array $taskIds): array
    {
        if (!$taskIds) { return []; }
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        $st = $this->db->prepare(
            "SELECT * FROM council_subtasks WHERE task_id IN ($in) ORDER BY position ASC, id ASC"
        );
        $st->execute($taskIds);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int) $row['task_id']][] = $row;
        }
        return $out;
    }

    // --- Подзадачи ---

    public function addSubtask(int $taskId, string $title): int
    {
        $countSt = $this->db->prepare('SELECT COUNT(*) FROM council_subtasks WHERE task_id = ?');
        $countSt->execute([$taskId]);
        $position = (int) $countSt->fetchColumn();

        $st = $this->db->prepare(
            'INSERT INTO council_subtasks (task_id, title, done, position) VALUES (?, ?, 0, ?)'
        );
        $st->execute([$taskId, $title, $position]);
        return (int) $this->db->lastInsertId();
    }

    public function findSubtask(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_subtasks WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function toggleSubtask(int $id, bool $done): void
    {
        $st = $this->db->prepare('UPDATE council_subtasks SET done = ? WHERE id = ?');
        $st->execute([$done ? 1 : 0, $id]);
    }

    public function renameSubtask(int $id, string $title): void
    {
        $st = $this->db->prepare('UPDATE council_subtasks SET title = ? WHERE id = ?');
        $st->execute([$title, $id]);
    }

    public function deleteSubtask(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM council_subtasks WHERE id = ?');
        $st->execute([$id]);
    }
}
