<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Database;
use SkazResidents\Repository\TelegramMessageRepository;
use PDO;

/**
 * Единая точка записи message_id отправленных Telegram-сообщений в журнал
 * (таблица telegram_messages). Её зовут из BotNotify/TelegramBot сразу после
 * успешной отправки или правки — чтобы позже можно было переписать/удалить
 * сообщение (у ботов нет «удалить последнее», нужен точный message_id).
 *
 * Надёжность важнее полноты журнала: если БД недоступна (CLI-скрипты без
 * соединения, тесты) или запись упала — глотаем исключение и пишем в error_log,
 * отправка сообщения от этого не страдает.
 */
final class TelegramOutbox
{
    private const TAG = 'TelegramOutbox';

    /**
     * Записать сообщение в журнал. Молча и ничего не делает, когда соединения
     * с БД нет. $kind: 'send' (по умолчанию) | 'edit'.
     */
    public static function record(string $chatId, int $messageId, string $context, string $kind = 'send', ?int $familyId = null, ?string $text = null): void
    {
        if ($chatId === '' || $messageId <= 0) { return; }
        try {
            if (self::pdo() === null) { return; }
            (new TelegramMessageRepository())->record($chatId, $messageId, $context, $kind, $familyId, $text);
        } catch (\Throwable $e) {
            error_log(self::TAG . ': не записали message_id ' . $messageId . ' (' . $chatId . '): ' . $e->getMessage());
        }
    }

    /** PDO из Database, но без исключения, когда подключения не было (CLI, тесты). */
    private static function pdo(): ?PDO
    {
        try {
            return Database::pdo();
        } catch (\Throwable) {
            return null;
        }
    }
}