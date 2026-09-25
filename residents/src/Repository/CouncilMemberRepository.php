<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Аккаунты членов Попечительского совета. Отдельная таблица council_members —
 * email независим от families (см. council-schema.sql). Приглашение-only:
 * аккаунты заводит администратор, поэтому создаются сразу active.
 */
final class CouncilMemberRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $email, string $passwordHash, string $name, string $role = 'member'): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_members (email, password_hash, name, status, role)
             VALUES (?, ?, ?, \'active\', ?)'
        );
        $st->execute([$email, $passwordHash, $name, $role]);
        return (int) $this->db->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findByTelegramId(int $telegramId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE telegram_id = ?');
        $st->execute([$telegramId]);
        return $st->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE name = ?');
        $st->execute([$name]);
        return $st->fetch() ?: null;
    }

    public function findByMaxId(int $maxId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_members WHERE max_user_id = ?');
        $st->execute([$maxId]);
        return $st->fetch() ?: null;
    }

    /** Срок жизни кода привязки, секунд. */
    public const CLAIM_CODE_TTL = 7 * 86400;

    /** Алфавит кода без похожих символов (0/O, 1/I/L): код диктуют голосом. */
    private const CLAIM_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Выдать члену совета одноразовый код привязки Telegram (прежний код
     * сгорает). В БД — только sha256, сам код видит лишь администратор.
     * @return string код вида «ABCD-EFGH»
     */
    public function issueClaimCode(int $id, int $now): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CLAIM_CODE_ALPHABET[random_int(0, strlen(self::CLAIM_CODE_ALPHABET) - 1)];
        }
        $st = $this->db->prepare('UPDATE council_members SET claim_code_hash = ?, claim_code_expires = ? WHERE id = ?');
        $st->execute([hash('sha256', $code), date('Y-m-d H:i:s', $now + self::CLAIM_CODE_TTL), $id]);
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /** Код в каноническом виде: регистр и разделители, как бы его ни ввели, не важны. */
    public static function normalizeClaimCode(string $code): string
    {
        return (string) preg_replace('~[^A-Z0-9]~', '', mb_strtoupper($code));
    }

    /**
     * Привязать Telegram по фамилии и коду администратора. Запись должна быть
     * активной, ещё не привязанной, код — непросроченным; после привязки код
     * гасится. Любой отказ — null без подробностей (нечего перебирать).
     * @return array<string,mixed>|null привязанная запись
     */
    public function claimTelegramByCode(string $surname, string $code, int $telegramId, int $now): ?array
    {
        $code = self::normalizeClaimCode($code);
        $surname = mb_strtolower(trim($surname));
        if ($code === '' || $surname === '') { return null; }

        $st = $this->db->prepare(
            "SELECT * FROM council_members
             WHERE claim_code_hash = ? AND telegram_id IS NULL AND status = 'active'"
        );
        $st->execute([hash('sha256', $code)]);
        foreach ($st->fetchAll() as $m) {
            if (mb_strtolower(trim((string) $m['surname'])) !== $surname) { continue; }
            if (strtotime((string) $m['claim_code_expires']) < $now) { continue; }
            $upd = $this->db->prepare(
                'UPDATE council_members SET telegram_id = ?, claim_code_hash = NULL, claim_code_expires = NULL
                 WHERE id = ? AND telegram_id IS NULL'
            );
            $upd->execute([$telegramId, (int) $m['id']]);
            if ($upd->rowCount() !== 1) { return null; }   // гонка: запись успели привязать
            return $this->findById((int) $m['id']);
        }
        return null;
    }

    /** Привязать Telegram-аккаунт к записи члена совета (клейм ростера). */
    public function bindTelegram(int $id, int $telegramId): void
    {
        $st = $this->db->prepare('UPDATE council_members SET telegram_id = ? WHERE id = ?');
        $st->execute([$telegramId, $id]);
    }

    /** Снять привязку Telegram (админ — на случай ошибочного клейма). */
    public function unbindTelegram(int $id): void
    {
        $st = $this->db->prepare('UPDATE council_members SET telegram_id = NULL WHERE id = ?');
        $st->execute([$id]);
    }

    /** Привязать MAX-аккаунт к записи члена совета (клейм ростера). */
    public function bindMax(int $id, int $maxId): void
    {
        $st = $this->db->prepare('UPDATE council_members SET max_user_id = ? WHERE id = ?');
        $st->execute([$maxId, $id]);
    }

    /** Снять привязку MAX (админ — на случай ошибочного клейма). */
    public function unbindMax(int $id): void
    {
        $st = $this->db->prepare('UPDATE council_members SET max_user_id = NULL WHERE id = ?');
        $st->execute([$id]);
    }

    /** Завести запись ростера (без Telegram). email/пароль синтетические — вход только через Telegram. */
    public function createRosterMember(string $name, string $surname, string $email, string $passwordHash): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_members (email, name, surname, password_hash, status, role)
             VALUES (?, ?, ?, ?, \'active\', \'member\')'
        );
        $st->execute([$email, $name, $surname, $passwordHash]);
        return (int) $this->db->lastInsertId();
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = $this->db->prepare('UPDATE council_members SET password_hash = ? WHERE id = ?');
        $st->execute([$passwordHash, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE council_members SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->query(
            'SELECT * FROM council_members ORDER BY created_at ASC'
        )->fetchAll();
    }

    /** Является ли аккаунт текущим Дежурным председателем. */
    public function isDutyChair(int $id): bool
    {
        $st = $this->db->prepare('SELECT is_duty_chair FROM council_members WHERE id = ?');
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    public function findDutyChair(): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM council_members WHERE is_duty_chair = 1 LIMIT 1'
        )->fetch();
        return $row ?: null;
    }

    /**
     * Назначить дежурного председателя. Роль переходящая и единоличная —
     * сбрасываем флаг у всех и ставим одному (в транзакции). id=0 — снять со всех.
     */
    public function setDutyChair(int $id): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('UPDATE council_members SET is_duty_chair = 0');
            if ($id > 0) {
                $st = $this->db->prepare('UPDATE council_members SET is_duty_chair = 1 WHERE id = ?');
                $st->execute([$id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
