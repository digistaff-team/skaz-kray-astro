<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Config, Database, TelegramBot};
use SkazResidents\Repository\TelegramMessageRepository;
use SkazResidents\Service\TelegramOutbox;

/**
 * Журнал message_id исходящих сообщений (таблица telegram_messages):
 * запись через TelegramOutbox, выборки, обрезка текста и — главное —
 * устойчивость: отсутствие соединения и сбой записи не роняют отправку.
 *
 * Сеть не дёргается: Config::set([]) → бот Token пустой → sendMessage сразу null.
 */
final class TelegramMessageRepositoryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        Config::set([]);   // без telegram → TelegramBot не ходит в сеть
    }

    protected function tearDown(): void
    {
        Config::set([]);   // не оставлять настройки следующим тестам
    }

    public function test_record_persists_chat_and_message_id(): void
    {
        TelegramOutbox::record('111', 42, 'personal', 'send', 7, 'привет');
        $row = (new TelegramMessageRepository())->lastForChat('111');
        $this->assertNotNull($row);
        $this->assertSame('111', (string) $row['chat_id']);
        $this->assertSame(42, (int) $row['message_id']);
        $this->assertSame(7, (int) $row['family_id']);
        $this->assertSame('personal', (string) $row['context']);
        $this->assertSame('send', (string) $row['kind']);
        $this->assertSame('привет', (string) $row['text']);
    }

    public function test_list_by_chat_returns_all_in_order(): void
    {
        TelegramOutbox::record('111', 1, 'broadcast', 'send', 7, 'а');
        TelegramOutbox::record('111', 2, 'broadcast', 'send', 7, 'б');
        TelegramOutbox::record('111', 2, 'broadcast', 'edit', 7, 'б-новый');
        TelegramOutbox::record('222', 9, 'group', 'send');
        $rows = (new TelegramMessageRepository())->listByChat('111');
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 2], array_map(static fn($r): int => (int) $r['message_id'], $rows));
        $this->assertSame('edit', (string) $rows[2]['kind']);
        $this->assertSame([], (new TelegramMessageRepository())->listByChat('999'));
    }

    public function test_record_ignores_empty_chat_and_non_positive_id(): void
    {
        TelegramOutbox::record('', 42, 'personal');
        TelegramOutbox::record('111', 0, 'personal');
        TelegramOutbox::record('111', -3, 'personal');
        $this->assertSame([], (new TelegramMessageRepository())->listByChat(''));
        $this->assertSame([], (new TelegramMessageRepository())->listByChat('111'));
    }

    public function test_long_text_is_truncated_to_column_limit(): void
    {
        $long = str_repeat('я', 1000);
        TelegramOutbox::record('111', 1, 'broadcast', 'send', null, $long);
        $row = (new TelegramMessageRepository())->lastForChat('111');
        $this->assertSame(500, mb_strlen((string) $row['text']));
    }

    /** Без соединения (CLI/тесты) запись молча пропускается — отправка не страдает. */
    public function test_record_without_db_connection_is_silent_noop(): void
    {
        $saved = $this->pdo;
        $this->setDbRef(null);                       // «соединения нет», как в CLI без вызова connect()
        try {
            TelegramOutbox::record('111', 42, 'personal');   // не должно бросить исключение
        } finally {
            $this->setDbRef($saved);                 // вернуть PDO для tearDown
        }
        $this->addToAssertionCount(1);   // главное — что дошли сюда без исключения
    }

    /** Сбой записи (нет таблицы) — только error_log, исключение не улетает наружу. */
    public function test_record_failure_does_not_throw(): void
    {
        $this->pdo->exec('DROP TABLE telegram_messages');
        TelegramOutbox::record('111', 42, 'personal');   // не должно бросить исключение
        $this->addToAssertionCount(1);
    }

    /**
     * sendMessage/sendMessageId возвращают ?int и молча null при пустом токене
     * или чате (guard до сети). Успех (message_id) проверяется только интеграционно
     * с реальным Bot API — здесь сеть не дёргаем.
     */
    public function test_send_message_returns_message_id_or_null(): void
    {
        $this->assertNull(TelegramBot::sendMessage('', '111', 'текст'));
        $this->assertNull(TelegramBot::sendMessage('token', '', 'текст'));
        $this->assertNull(TelegramBot::sendMessageId('', '111', 'текст'));
        $this->assertNull(TelegramBot::sendMessageId('token', '', 'текст'));
    }

    /** Подменить/обнулить статический PDO в Database (set() принимает только PDO). */
    private function setDbRef(?\PDO $pdo): void
    {
        $r = new \ReflectionProperty(Database::class, 'pdo');
        $r->setAccessible(true);
        $r->setValue(null, $pdo);
    }
}