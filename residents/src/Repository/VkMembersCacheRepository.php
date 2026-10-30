<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Кеш подписчиков ВК-группы «Вече» — одна строка с id = 1 в таблице
 * vk_group_members_cache (config/vk-members-cache.sql).
 *
 * Зачем кеш: groups.getMembers стоит один вызов API, а лимит для новых
 * приложений — 10 000 вызовов в месяц (правила VK от 19.08.2026). Состав
 * группы меняется редко, поэтому cron (bin/vk-vechesk-sync.php, каждые 15
 * минут) берёт подписчиков из кеша и обновляет его раз в сутки.
 *
 * Upsert портируемый (SELECT + UPDATE/INSERT): ON CONFLICT — синтаксис SQLite,
 * MariaDB его не понимает, а ON DUPLICATE KEY наоборот. См.
 * SectionSettingsRepository::setEnabled.
 */
final class VkMembersCacheRepository
{
    private const CACHE_ROW = 1;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** Подписчики из кеша или null, если кеша нет. @return array<int,array<string,mixed>>|null */
    public function members(int $groupId): ?array
    {
        $st = $this->db->prepare(
            'SELECT payload FROM vk_group_members_cache WHERE id = ? AND group_id = ?'
        );
        $st->execute([self::CACHE_ROW, $groupId]);
        $payload = $st->fetchColumn();
        if ($payload === false) { return null; }
        $items = json_decode((string) $payload, true);
        return is_array($items) ? $items : null;
    }

    /** Время последнего обновления кеша ('' — кеша нет). */
    public function fetchedAt(int $groupId): string
    {
        $st = $this->db->prepare(
            'SELECT fetched_at FROM vk_group_members_cache WHERE id = ? AND group_id = ?'
        );
        $st->execute([self::CACHE_ROW, $groupId]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    /**
     * Свежий ли кеш: записан не раньше $maxAge секунд назад и для той же группы.
     * Пустое fetched_at или другая группа → false.
     */
    public function isFresh(int $groupId, string $now, int $maxAge): bool
    {
        $fetchedAt = $this->fetchedAt($groupId);
        if ($fetchedAt === '') { return false; }
        $at  = strtotime($fetchedAt);
        $cur = strtotime($now);
        if ($at === false || $cur === false) { return false; }
        return $at > $cur - $maxAge;
    }

    /** @param array<int,array<string,mixed>> $members */
    public function save(int $groupId, array $members, string $now): void
    {
        $payload = (string) json_encode($members, JSON_UNESCAPED_UNICODE);
        $exists = $this->db->prepare('SELECT 1 FROM vk_group_members_cache WHERE id = ?');
        $exists->execute([self::CACHE_ROW]);
        if ($exists->fetchColumn() !== false) {
            $st = $this->db->prepare(
                'UPDATE vk_group_members_cache SET group_id = ?, payload = ?, fetched_at = ? WHERE id = ?'
            );
            $st->execute([$groupId, $payload, $now, self::CACHE_ROW]);
            return;
        }
        $st = $this->db->prepare(
            'INSERT INTO vk_group_members_cache (id, group_id, payload, fetched_at) VALUES (?, ?, ?, ?)'
        );
        $st->execute([self::CACHE_ROW, $groupId, $payload, $now]);
    }

    /** Забыть кеш (отключение группы, смена group_id). */
    public function clear(): void
    {
        $st = $this->db->prepare('DELETE FROM vk_group_members_cache WHERE id = ?');
        $st->execute([self::CACHE_ROW]);
    }
}
