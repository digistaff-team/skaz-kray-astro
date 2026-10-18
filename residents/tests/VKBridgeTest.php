<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Database;
use SkazResidents\Service\VKBridge;
use SkazResidents\Repository\TelegramMessageRepository;

/**
 * Пересылка постов ВК-группы «Вече» в рассылку (VKBridge): курсор состояния,
 * отбор получателей (список + telegram_id + vk-аккаунт в подписчиках ВК),
 * dry-run, нормализация residents.vk. Сеть не трогаем — зависимости
 * подменены колбэками, БД — SQLite in-memory (make_test_db).
 */
final class VKBridgeTest extends TestCase
{
    private const LIST_ID = 7;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        $this->seed();
    }

    /** Семьи, поместья, жители с vk: 1 — подписчик, 2 — не подписан, 3 — без telegram, 5 — без vk. */
    private function seed(): void
    {
        $insFam = $this->pdo->prepare(
            "INSERT INTO families (id, email, password_hash, name, status, telegram_id)
             VALUES (?, ?, 'x', ?, 'active', ?)"
        );
        $insFam->execute([1, 'a@x', 'Альфы', 1001]);
        $insFam->execute([2, 'b@x', 'Беты', 1002]);
        $insFam->execute([3, 'c@x', 'Гаммы', null]);
        $insFam->execute([4, 'd@x', 'Дельты', 1004]);
        $insFam->execute([5, 'e@x', 'Эты', 1005]);

        $insHh = $this->pdo->prepare("INSERT INTO households (id, family_id, glade) VALUES (?, ?, '1')");
        foreach ([1, 2, 3, 4, 5] as $i) {
            $insHh->execute([$i, $i]);
        }

        $insR = $this->pdo->prepare('INSERT INTO residents (household_id, full_name, vk) VALUES (?, ?, ?)');
        $insR->execute([1, 'Иван', 'vk.com/ivan']);
        $insR->execute([2, 'Пётр', 'vk.com/petr']);
        $insR->execute([3, 'Олег', 'id555']);
        $insR->execute([4, 'Дед', 'vk.com/ded']);   // семья 4 — не в списке
        $insR->execute([5, 'Ева', '']);             // пустой vk — не кандидат

        $this->pdo->exec("INSERT INTO broadcast_lists (id, name) VALUES (" . self::LIST_ID . ", 'Участники Вече')");
        $insM = $this->pdo->prepare('INSERT INTO broadcast_list_members (list_id, family_id) VALUES (?, ?)');
        foreach ([1, 2, 3, 5] as $fid) {
            $insM->execute([self::LIST_ID, $fid]);
        }
        // Строка-чат в списке — не должна попадать в кандидаты.
        $this->pdo->exec(
            'INSERT INTO broadcast_list_members (list_id, family_id, chat_id, chat_title)
             VALUES (' . self::LIST_ID . ", 0, -100, 'Чат Вече')"
        );
    }

    /** Мост с подменёнными зависимостями. */
    private function bridge(array $posts, array $members, array &$sent, bool $dryRun = false): VKBridge
    {
        return new VKBridge(
            Database::pdo(),
            static fn(int $gid): array => $posts,
            static fn(int $gid): array => $members,
            static function (int $familyId, int $telegramId, string $text) use (&$sent): int {
                $sent[] = ['family' => $familyId, 'tg' => $telegramId, 'text' => $text];
                return 42;   // «message_id»
            },
            $dryRun
        );
    }

    private function conf(): array
    {
        return [
            'list_id'    => self::LIST_ID,
            'group_id'   => 63502598,
            'list_name'  => 'Участники Вече',
            'group_name' => 'Вече',
        ];
    }

    // ---- тесты -----------------------------------------------------------

    /** Первый запуск: ничего не рассылаем, только запоминаем последний пост. */
    public function test_first_run_only_records_cursor(): void
    {
        $sent = [];
        $b = $this->bridge(
            [['id' => 500, 'date' => 0, 'text' => 'старый'], ['id' => 501, 'date' => 0, 'text' => 'новее']],
            [['id' => 9, 'screen_name' => 'ivan']],
            $sent
        );
        $stats = $b->sync($this->conf());
        $this->assertSame([], $sent);
        $this->assertSame(2, $stats['new_posts']);
        $this->assertSame(501, $b->lastPostId());   // запомнили последний, старые не рассылали
    }

    /** Новый пост уходит только подписчику-семье, курсор поезжал, журнал исходящих записан. */
    public function test_new_post_sent_only_to_subscribed_family(): void
    {
        $sent = [];
        $this->pdo->exec('INSERT INTO vk_vechesk_state (id, last_post_id) VALUES (1, 500)');

        $b = $this->bridge(
            [['id' => 501, 'date' => 1730000000, 'text' => 'Встреча в субботу']],
            [['id' => 11, 'screen_name' => 'ivan']],   // подписан только Иван
            $sent
        );
        $stats = $b->sync($this->conf());

        $this->assertCount(1, $sent);
        $this->assertSame(1, $sent[0]['family']);
        $this->assertSame(1001, $sent[0]['tg']);
        $this->assertStringContainsString('Встреча в субботу', $sent[0]['text']);
        $this->assertStringContainsString('vk.ru/wall-63502598_501', $sent[0]['text']);
        $this->assertStringContainsString('ВК-группе «Вече»', $sent[0]['text']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(501, $b->lastPostId());

        $rows = (new TelegramMessageRepository())->listByChat('1001');
        $this->assertNotEmpty($rows);
        $this->assertSame(VKBridge::CONTEXT, (string) $rows[0]['context']);
        $this->assertSame(42, (int) $rows[0]['message_id']);
        $this->assertSame(1, (int) $rows[0]['family_id']);
    }

    /** Второй прогон тех же постов — ничего не дублируется. */
    public function test_already_forwarded_posts_are_skipped(): void
    {
        $sent = [];
        $this->pdo->exec('INSERT INTO vk_vechesk_state (id, last_post_id) VALUES (1, 501)');
        $b = $this->bridge(
            [['id' => 501, 'date' => 0, 'text' => 'уже был']],
            [['id' => 11, 'screen_name' => 'ivan']],
            $sent
        );
        $stats = $b->sync($this->conf());
        $this->assertSame([], $sent);
        $this->assertSame(0, $stats['new_posts']);
    }
/** Сопоставление vk-аккаунта — по числовому id подписчика (residents.vk = «id555»). */
    public function test_match_by_numeric_id_account(): void
    {
        $sent = [];
        $this->pdo->exec('INSERT INTO vk_vechesk_state (id, last_post_id) VALUES (1, 500)');
        // Олег (семья 3) — в списке, но telegram_id нет → не кандидат; поднимаем.
        $this->pdo->exec('UPDATE families SET telegram_id = 3003 WHERE id = 3');

        $b = $this->bridge(
            [['id' => 501, 'date' => 0, 'text' => 'пост']],
            [['id' => 555]],   // подписчик без screen_name — матчится по ключу id555
            $sent
        );
        $b->sync($this->conf());
        $this->assertCount(1, $sent);
        $this->assertSame(3, $sent[0]['family']);

        // Без совпадения ключей (id 77 никому не соответствует) — отправок нет.
        $sent = [];
        $b = $this->bridge(
            [['id' => 502, 'date' => 0, 'text' => 'пост2']],
            [['id' => 77]],
            $sent
        );
        $b->sync($this->conf());
        $this->assertSame([], $sent);
    }

    /** dry-run: показывает адресатов, но не шлёт и не двигает курсор. */
    public function test_dry_run_sends_nothing_and_keeps_cursor(): void
    {
        $sent = [];
        $this->pdo->exec('INSERT INTO vk_vechesk_state (id, last_post_id) VALUES (1, 500)');
        $b = $this->bridge(
            [['id' => 501, 'date' => 0, 'text' => 'пост']],
            [['id' => 11, 'screen_name' => 'ivan']],
            $sent,
            true
        );
        $stats = $b->sync($this->conf());
        $this->assertSame([], $sent);
        $this->assertSame(1, $stats['sent']);        // «отправил бы»
        $this->assertSame(500, $b->lastPostId());    // курсор не изменился
    }

    /** Ни у кого из кандидатов нет подписки — пост помечен, но никому не ушёл. */
    public function test_post_without_subscribers_is_marked_done(): void
    {
        $sent = [];
        $this->pdo->exec('INSERT INTO vk_vechesk_state (id, last_post_id) VALUES (1, 500)');
        $b = $this->bridge(
            [['id' => 501, 'date' => 0, 'text' => 'пост']],
            [['id' => 77, 'screen_name' => 'kto-to']],
            $sent
        );
        $stats = $b->sync($this->conf());
        $this->assertSame([], $sent);
        $this->assertSame(1, $stats['no_subscribers']);
        $this->assertSame(501, $b->lastPostId());
    }

    /** Нормализация residents.vk в ключи сверки с подписчиками. */
    public function test_vk_keys_normalization(): void
    {
        $this->assertSame(['ivan'], VKBridge::vkKeys('vk.com/ivan'));
        $this->assertSame(['ivan'], VKBridge::vkKeys(' https://vk.ru/ivan/ '));
        $this->assertSame(['id123'], VKBridge::vkKeys('id123'));
        $this->assertSame(['12345', 'id12345'], VKBridge::vkKeys('12345'));
        $this->assertSame([], VKBridge::vkKeys('   '));
    }
}