<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Сохранённые списки адресатов массовой рассылки (broadcast_lists +
 * broadcast_list_members). Список — именованный набор поместий: админ составляет
 * его один раз и рассылает по нему, не отмечая адресатов заново.
 *
 * Членство намеренно НЕ ссылается на Telegram: привязка бота у семьи меняется,
 * а список остаётся. Какие из членов реально получат сообщение, решает
 * BroadcastController::resolveRecipients на момент отправки (только активные
 * семьи с telegram_id).
 */
final class BroadcastListRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** Все списки с числом членов — для панели на странице рассылки. *
     * @return array<int,array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->db->query(
            'SELECT l.id, l.name,
                    (SELECT COUNT(*) FROM broadcast_list_members m WHERE m.list_id = l.id) AS members
             FROM broadcast_lists l
             ORDER BY l.name'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT id, name FROM broadcast_lists WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Новое название списка. Возвращает id. */
    public function create(string $name): int
    {
        $st = $this->db->prepare('INSERT INTO broadcast_lists (name) VALUES (?)');
        $st->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $st = $this->db->prepare('UPDATE broadcast_lists SET name = ? WHERE id = ?');
        $st->execute([$name, $id]);
    }

    /** Список и его члены (с названиями поместий) — страница редактирования. *
     * @return array<int,array<string,mixed>>
     */
    public function members(int $listId): array
    {
        $st = $this->db->prepare(
            'SELECT m.family_id, f.name, f.status, f.telegram_id
             FROM broadcast_list_members m
             JOIN families f ON f.id = m.family_id
             WHERE m.list_id = ?
             ORDER BY f.name'
        );
        $st->execute([$listId]);
        return $st->fetchAll();
    }

    /** Полное удаление списка (члены уходят каскадом). */
    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM broadcast_lists WHERE id = ?');
        $st->execute([$id]);
    }

    /** Добавить семью в список. Уже добавленная — no-op (PK на паре). */
    public function addMember(int $listId, int $familyId): void
    {
        // Вставляем только если члена ещё нет: INSERT IGNORE — MySQL-диалект,
        // а в тестах SQLite. Проверка SELECT'ом — переносимо без диалектов
        // (как SectionSettingsRepository::setEnabled).
        $st = $this->db->prepare(
            'SELECT 1 FROM broadcast_list_members WHERE list_id = ? AND family_id = ?'
        );
        $st->execute([$listId, $familyId]);
        if ($st->fetchColumn() !== false) { return; }
        $st = $this->db->prepare(
            'INSERT INTO broadcast_list_members (list_id, family_id) VALUES (?, ?)'
        );
        $st->execute([$listId, $familyId]);
    }

    /** Убрать семью из списка. */
    public function removeMember(int $listId, int $familyId): void
    {
        $st = $this->db->prepare(
            'DELETE FROM broadcast_list_members WHERE list_id = ? AND family_id = ?'
        );
        $st->execute([$listId, $familyId]);
    }

    /**
     * Адресаты рассылки по списку: активные семьи с привязанным Telegram —
     * ровно тот же отбор, что и у «Всем с Telegram». Члены без Telegram
     * пропускаются (в журнал уйдёт error_log из BroadcastController).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recipients(int $listId): array
    {
        $st = $this->db->prepare(
            "SELECT f.id, f.name, f.telegram_id
             FROM broadcast_list_members m
             JOIN families f ON f.id = m.family_id
             WHERE m.list_id = ? AND f.status = 'active' AND f.telegram_id IS NOT NULL
             ORDER BY f.name"
        );
        $st->execute([$listId]);
        return $st->fetchAll();
    }
}