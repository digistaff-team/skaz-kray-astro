<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class FamilyRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function createPending(string $email, string $passwordHash, string $name): int
    {
        $st = $this->db->prepare(
            'INSERT INTO families (email, password_hash, name, status, role)
             VALUES (?, ?, ?, \'pending\', \'resident\')'
        );
        $st->execute([$email, $passwordHash, $name]);
        return (int) $this->db->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function findByTelegramId(int $telegramId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE telegram_id = ?');
        $st->execute([$telegramId]);
        return $st->fetch() ?: null;
    }

    /**
     * Создаёт аккаунт жителя, привязанный к Telegram: сразу active (членство в
     * группе — гейт вместо одобрения редактором). Email синтетический, пароль —
     * случайный неиспользуемый (вход только через Telegram). $username — @username
     * из initData (для ссылки «Tg» в справочнике), может отсутствовать.
     */
    public function createTelegramFamily(int $telegramId, string $name, ?string $username = null): int
    {
        $email = 'tg' . $telegramId . '@telegram.local';
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $st = $this->db->prepare(
            'INSERT INTO families (email, telegram_id, telegram_username, password_hash, name, status, role, approved_at)
             VALUES (?, ?, ?, ?, ?, \'active\', \'resident\', ?)'
        );
        $st->execute([$email, $telegramId, $username, $passwordHash, $name, date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    public function findByMaxId(int $maxUserId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE max_user_id = ?');
        $st->execute([$maxUserId]);
        return $st->fetch() ?: null;
    }

    /**
     * Создаёт аккаунт жителя, привязанный к MAX: сразу active (членство в группе
     * жителей MAX — гейт вместо одобрения редактором). Email синтетический
     * (max<id>@max.local), пароль случайный неиспользуемый (вход только через MAX).
     * Аналог createTelegramFamily; отдельной «ссылки MAX» в справочнике нет,
     * поэтому username не храним.
     */
    public function createMaxFamily(int $maxUserId, string $name): int
    {
        $email = 'max' . $maxUserId . '@max.local';
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $st = $this->db->prepare(
            'INSERT INTO families (email, max_user_id, password_hash, name, status, role, approved_at)
             VALUES (?, ?, ?, ?, \'active\', \'resident\', ?)'
        );
        $st->execute([$email, $maxUserId, $passwordHash, $name, date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    /** Обновляет сохранённый @username аккаунта (держим свежим при каждом входе). */
    public function setTelegramUsername(int $id, ?string $username): void
    {
        $st = $this->db->prepare('UPDATE families SET telegram_username = ? WHERE id = ?');
        $st->execute([$username, $id]);
    }

    /**
     * Переносит привязку MAX с только что созданного одноразового аккаунта
     * ($throwawayId, вход через MAX) на существующий аккаунт поместья ($targetId,
     * обычно Telegram/email) и удаляет одноразовый. Итог: одна личность — один
     * аккаунт с двумя входами (Telegram + MAX). Транзакция; сначала освобождаем
     * UNIQUE(max_user_id) на одноразовом, затем ставим на целевой.
     *
     * Вызывать только когда одноразовый аккаунт свежий (без поместья/контента) —
     * проверки на стороне вызова (ProfileController::tryLinkMax). Если у аккаунта
     * всё же есть ссылки (FK) — DELETE бросит исключение, транзакция откатится.
     */
    public function mergeMaxInto(int $throwawayId, int $targetId, int $maxUserId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE families SET max_user_id = NULL WHERE id = ?')->execute([$throwawayId]);
            $this->db->prepare('UPDATE families SET max_user_id = ? WHERE id = ?')->execute([$maxUserId, $targetId]);
            $this->db->prepare('DELETE FROM families WHERE id = ?')->execute([$throwawayId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE families SET status = \'active\', approved_at = ? WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE families SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = $this->db->prepare('UPDATE families SET password_hash = ? WHERE id = ?');
        $st->execute([$passwordHash, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listByStatus(string $status): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM families WHERE status = ? ORDER BY created_at ASC'
        );
        $st->execute([$status]);
        return $st->fetchAll();
    }
}
