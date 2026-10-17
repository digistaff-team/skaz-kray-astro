<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Журнал исходящих Telegram-сообщений (таблица telegram_messages): chat_id +
 * message_id каждого отправленного сообщения, чтобы позже переписать его
 * (TelegramBot::editMessageText) или удалить — у ботов нет метода «удалить
 * последнее», правка/удаление требуют точный message_id.
 *
 * Пишется из BotNotify/TelegramBot сразу после успешного sendMessage. Сбой
 * записи (БД недоступна, CLI без соединения) НИКОГДА не роняет отправку —
 * только error_log: сообщение важнее строки журнала.
 */
final class TelegramMessageRepository
{
    private const TEXT_LIMIT = 500;   // в колонке VARCHAR(500) — обрезаем при записи

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Записать отправленное/переписанное сообщение. $kind: 'send' | 'edit'.
     * $familyId — только для личных сообщений семье, иначе null.
     * Снаружи звать через {@see TelegramOutbox::record} — там защита от
     * отсутствия соединения (CLI, тесты без БД) и error_log вместо исключения.
     */
    public function record(string $chatId, int $messageId, string $context, string $kind = 'send', ?int $familyId = null, ?string $text = null): void
    {
        if ($chatId === '' || $messageId <= 0) { return; }
        $st = $this->db->prepare(
            'INSERT INTO telegram_messages (chat_id, message_id, family_id, context, kind, text)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $chatId,
            $messageId,
            $familyId,
            $context,
            $kind,
            $text !== null ? mb_substr($text, 0, self::TEXT_LIMIT) : null,
        ]);
    }

    /**
     * Все message_id, записанные для chat_id (для правки/удаления сообщений).
     * @return array<int,array<string,mixed>>
     */
    public function listByChat(string $chatId): array
    {
        if ($chatId === '') { return []; }
        $st = $this->db->prepare('SELECT * FROM telegram_messages WHERE chat_id = ? ORDER BY id');
        $st->execute([$chatId]);
        return $st->fetchAll();
    }

    /** Последнее отправленное в chat_id сообщение (или null, если журнала нет). */
    public function lastForChat(string $chatId): ?array
    {
        if ($chatId === '') { return null; }
        $st = $this->db->prepare('SELECT * FROM telegram_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$chatId]);
        return $st->fetch() ?: null;
    }
}