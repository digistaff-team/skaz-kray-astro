<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Ключи доступа VK ID и кеш подписчиков ВК-группы «Вече» — две строки с id = 1
 * (таблицы vk_oauth_token и vk_group_members_cache, config/vk-oauth-token.sql).
 *
 * Токены лежат в БД, а не в config/.env, потому что user-токен VK живёт час,
 * а refresh_token ротируется при каждом обновлении: правка .env каждый час
 * нереальна. Кеш подписчиков — из-за лимита API (10 000 вызовов в месяц без
 * верификации бизнес-профиля): groups.getMembers дорог, а состав группы
 * меняется редко.
 *
 * Upsert портируемый (SELECT + UPDATE/INSERT): ON CONFLICT — синтаксис SQLite,
 * MariaDB его не понимает, а ON DUPLICATE KEY наоборот. См.
 * SectionSettingsRepository::setEnabled.
 */
final class VkOauthTokenRepository
{
    private const TOKEN_ROW = 1;
    private const CACHE_ROW = 1;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Сохранённая связка токенов или null, если вход в VK ещё не выполняли.
     * @return array{access_token:string,refresh_token:string,expires_at:string,last_error:?string}|null
     */
    public function tokens(): ?array
    {
        $st = $this->db->prepare(
            'SELECT access_token, refresh_token, expires_at, last_error
             FROM vk_oauth_token WHERE id = ?'
        );
        $st->execute([self::TOKEN_ROW]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Записать новую связку (после обмена code или обновления по refresh_token). */
    public function save(string $accessToken, string $refreshToken, string $expiresAt, string $now): void
    {
        $exists = $this->db->prepare('SELECT 1 FROM vk_oauth_token WHERE id = ?');
        $exists->execute([self::TOKEN_ROW]);
        if ($exists->fetchColumn() !== false) {
            $st = $this->db->prepare(
                'UPDATE vk_oauth_token
                 SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = ?, last_error = NULL
                 WHERE id = ?'
            );
            $st->execute([$accessToken, $refreshToken, $expiresAt, $now, self::TOKEN_ROW]);
            return;
        }
        $st = $this->db->prepare(
            'INSERT INTO vk_oauth_token (id, access_token, refresh_token, expires_at, updated_at, last_error)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $st->execute([self::TOKEN_ROW, $accessToken, $refreshToken, $expiresAt, $now]);
    }

    /**
     * Запомнить ошибку VK (токен отозван, refresh не сработал) — чтобы админ
     * видел причину на странице подключения, а не искал её в логах.
     */
    public function markError(string $message, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE vk_oauth_token SET last_error = ?, updated_at = ? WHERE id = ?'
        );
        $st->execute([mb_substr($message, 0, 255), $now, self::TOKEN_ROW]);
    }

    /** Удалить связку токенов (отключение доступа). */
    public function clear(): void
    {
        $st = $this->db->prepare('DELETE FROM vk_oauth_token WHERE id = ?');
        $st->execute([self::TOKEN_ROW]);
    }

    /** Подписчики группы из кеша или null, если кеша нет. * @return array<int,array<string,mixed>>|null */
    public function membersCache(int $groupId): ?array
    {
        $st = $this->db->prepare(
            'SELECT payload, fetched_at FROM vk_group_members_cache WHERE id = ? AND group_id = ?'
        );
        $st->execute([self::CACHE_ROW, $groupId]);
        $row = $st->fetch();
        if (!$row) { return null; }
        $items = json_decode((string) $row['payload'], true);
        return is_array($items) ? $items : null;
    }

    /** Время последнего обновления кеша подписчиков ('' — кеша нет). */
    public function membersFetchedAt(int $groupId): string
    {
        $st = $this->db->prepare(
            'SELECT fetched_at FROM vk_group_members_cache WHERE id = ? AND group_id = ?'
        );
        $st->execute([self::CACHE_ROW, $groupId]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    /** @param array<int,array<string,mixed>> $members */
    public function saveMembers(int $groupId, array $members, string $now): void
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
}
