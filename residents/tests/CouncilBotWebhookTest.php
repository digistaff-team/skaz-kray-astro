<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Config;
use SkazResidents\Controller\Council\BotWebhookController;
use SkazResidents\Repository\{CouncilTaskRepository, CouncilMeetingRepository};

/**
 * Вебхук @SkazKray_bot: кнопки под сообщениями совета. Проверяем, КТО может
 * нажать (исполнитель, дежурный, казначей; заблокированный — никто) и что
 * меняется в БД. Токен пустой — в Telegram ничего не уходит.
 */
final class CouncilBotWebhookTest extends TestCase
{
    private \PDO $pdo;
    private BotWebhookController $hook;

    protected function setUp(): void
    {
        Config::set(['council_expense_approver' => 'Сергей Шубин']);
        $this->pdo = make_test_db();
        $this->pdo->exec("INSERT INTO council_members (id,email,telegram_id,password_hash,name,surname,status,role) VALUES
            (1,'a@tg.local',100,'H','Иван Петров','Петров','active','member'),
            (2,'b@tg.local',200,'H','Сергей Шубин','Шубин','active','member'),
            (3,'c@tg.local',300,'H','Анна Смирнова','Смирнова','blocked','member')");
        $this->pdo->exec("INSERT INTO council_meeting (id, duty_chair, rotation_index) VALUES (1, 'Иван Петров', 4)");
        $this->hook = new BotWebhookController();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);
    }

    private function task(string $assignee, array $over = []): int
    {
        $d = array_merge(['status' => 'новая', 'spent' => 0, 'cat' => null, 'exp' => 'none'], $over);
        $st = $this->pdo->prepare(
            "INSERT INTO council_tasks (title, assignee, status, spent, expense_category_id, expense_status) VALUES ('Покосить', ?, ?, ?, ?, ?)"
        );
        $st->execute([$assignee, $d['status'], $d['spent'], $d['cat'], $d['exp']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function press(int $fromTgId, string $data): void
    {
        $this->hook->dispatch(['callback_query' => [
            'id' => 'cb1', 'data' => $data, 'from' => ['id' => $fromTgId],
            'message' => ['message_id' => 5, 'chat' => ['id' => 100], 'text' => 'Задача'],
        ]], '');
    }

    private function taskRow(int $id): array
    {
        return (new CouncilTaskRepository())->find($id);
    }

    // --- Проверка подлинности ---------------------------------------------

    public function test_wrong_secret_is_rejected_without_processing(): void
    {
        Config::set(['telegram' => ['webhook_secret' => 'S3CRET', 'bot_token' => '']]);
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'wrong';
        ob_start();
        $this->hook->handle();
        $this->assertSame('', ob_get_clean());
    }

    public function test_empty_configured_secret_rejects_everything(): void
    {
        Config::set(['telegram' => ['webhook_secret' => '', 'bot_token' => '']]);
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = '';
        ob_start();
        $this->hook->handle();
        $this->assertSame('', ob_get_clean());
    }

    public function test_correct_secret_is_acknowledged(): void
    {
        Config::set(['telegram' => ['webhook_secret' => 'S3CRET', 'bot_token' => '']]);
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'S3CRET';
        ob_start();
        $this->hook->handle();
        $this->assertSame('ok', ob_get_clean());
    }

    // --- Кнопки задачи ----------------------------------------------------

    public function test_assignee_takes_task_and_reports_progress(): void
    {
        $id = $this->task('Иван Петров');
        $this->press(100, "t:{$id}:take");
        $this->assertSame('в работе', $this->taskRow($id)['status']);
        $this->press(100, "t:{$id}:p50");
        $this->assertSame(50, (int) $this->taskRow($id)['progress']);
        $this->press(100, "t:{$id}:done");
        $this->assertSame('выполнена', $this->taskRow($id)['status']);
        $this->assertSame(100, (int) $this->taskRow($id)['progress']);
    }

    public function test_decline_frees_task(): void
    {
        $id = $this->task('Иван Петров');
        $this->press(100, "t:{$id}:decline");
        $row = $this->taskRow($id);
        $this->assertSame('', $row['assignee']);
        $this->assertSame('новая', $row['status']);
    }

    public function test_other_member_cannot_touch_task(): void
    {
        $id = $this->task('Иван Петров');
        $this->press(200, "t:{$id}:take");
        $this->assertSame('новая', $this->taskRow($id)['status']);
    }

    public function test_unknown_telegram_user_cannot_touch_task(): void
    {
        $id = $this->task('Иван Петров');
        $this->press(999, "t:{$id}:done");
        $this->assertSame('новая', $this->taskRow($id)['status']);
    }

    public function test_blocked_assignee_loses_buttons(): void
    {
        $id = $this->task('Анна Смирнова');
        $this->press(300, "t:{$id}:take");
        $this->assertSame('новая', $this->taskRow($id)['status']);
    }

    public function test_garbage_callback_data_is_ignored(): void
    {
        $id = $this->task('Иван Петров');
        $this->press(100, "t:{$id}:drop_table");
        $this->hook->dispatch(['message' => ['text' => '/start']], '');
        $this->assertSame('новая', $this->taskRow($id)['status']);
    }

    // --- Дежурство ----------------------------------------------------------

    public function test_duty_chair_acknowledges_current_rotation(): void
    {
        $this->press(100, 'd:ack:4');
        $this->assertNotNull((new CouncilMeetingRepository())->dutyAckAt());
    }

    public function test_stale_duty_button_is_ignored(): void
    {
        $this->press(100, 'd:ack:3');
        $this->assertNull((new CouncilMeetingRepository())->dutyAckAt());
    }

    public function test_only_duty_chair_can_acknowledge(): void
    {
        $this->press(200, 'd:ack:4');
        $this->assertNull((new CouncilMeetingRepository())->dutyAckAt());
    }

    // --- Одобрение расходов -------------------------------------------------

    public function test_only_treasurer_approves_expense(): void
    {
        $this->pdo->exec("INSERT INTO council_ledger_categories (id, kind, name, position) VALUES (1, 'expense', 'Инвентарь', 0)");
        $id = $this->task('Иван Петров', ['status' => 'выполнена', 'spent' => 1500, 'cat' => 1, 'exp' => 'pending']);

        $this->press(100, "x:{$id}:approve");   // исполнитель — не казначей
        $this->assertSame('pending', $this->taskRow($id)['expense_status']);

        $this->press(200, "x:{$id}:approve");   // казначей
        $this->assertSame('approved', $this->taskRow($id)['expense_status']);
    }

    public function test_treasurer_rejects_expense(): void
    {
        $this->pdo->exec("INSERT INTO council_ledger_categories (id, kind, name, position) VALUES (1, 'expense', 'Инвентарь', 0)");
        $id = $this->task('Иван Петров', ['status' => 'выполнена', 'spent' => 1500, 'cat' => 1, 'exp' => 'pending']);
        $this->press(200, "x:{$id}:reject");
        $this->assertSame('rejected', $this->taskRow($id)['expense_status']);
    }
}
