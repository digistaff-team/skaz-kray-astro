<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Config;
use SkazResidents\Repository\{CouncilTaskRepository, CouncilLedgerRepository};
use SkazResidents\Service\CouncilExpenseApproval;

/**
 * Одобрение расходов задач Совета: расход попадает в бюджет только после approve,
 * reject убирает его, запрос шлётся лишь у выполненной задачи с суммой и статьёй.
 * Telegram не дёргается — в тестах Config пуст, botToken='' (отправка пропускается).
 */
final class CouncilExpenseApprovalTest extends TestCase
{
    private \PDO $pdo;
    private CouncilExpenseApproval $svc;

    protected function setUp(): void
    {
        Config::set([]); // без telegram → сеть не дёргается
        $this->pdo = make_test_db();
        // Статья расхода для отчёта.
        $this->pdo->exec("INSERT INTO council_ledger_categories (id, kind, name, position) VALUES
            (1, 'expense', 'Инвентарь', 0)");
        $this->svc = new CouncilExpenseApproval();
    }

    /** @param array<string,mixed> $over */
    private function task(array $over = []): int
    {
        $d = array_merge([
            'title' => 'Купить лопаты', 'assignee' => 'Иван Иванов', 'author' => 'Совет',
            'status' => 'выполнена', 'spent' => 1500, 'cat' => 1, 'exp' => 'none',
        ], $over);
        $st = $this->pdo->prepare(
            "INSERT INTO council_tasks (title, assignee, author, status, progress, spent, expense_category_id, expense_status)
             VALUES (?, ?, ?, ?, 100, ?, ?, ?)"
        );
        $st->execute([$d['title'], $d['assignee'], $d['author'], $d['status'], $d['spent'], $d['cat'], $d['exp']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function statusOf(int $id): string
    {
        return (string) (new CouncilTaskRepository())->find($id)['expense_status'];
    }

    /** Проставить сохранённое сообщение-запрос (эмуляция ответа Telegram). */
    private function setMsg(int $id, string $chatId, int $msgId): void
    {
        (new CouncilTaskRepository())->updateFields(
            $id,
            ['expense_msg_chat_id' => $chatId, 'expense_msg_id' => $msgId],
            date('Y-m-d H:i:s')
        );
    }

    /** @return array{0:?string,1:?int} [chat_id, message_id] сохранённого запроса */
    private function msgOf(int $id): array
    {
        $t = (new CouncilTaskRepository())->find($id);
        $mid = $t['expense_msg_id'] ?? null;
        return [$t['expense_msg_chat_id'] ?? null, $mid === null ? null : (int) $mid];
    }

    public function test_request_sets_pending_without_ledger(): void
    {
        $id = $this->task();
        $this->svc->requestIfNeeded($id);
        $this->assertSame('pending', $this->statusOf($id));
        $this->assertNull((new CouncilLedgerRepository())->findByTask($id), 'до одобрения расхода в бюджете нет');
    }

    public function test_request_skipped_when_no_category(): void
    {
        $id = $this->task(['cat' => null]);
        $this->assertFalse($this->svc->requestIfNeeded($id));
        $this->assertSame('none', $this->statusOf($id));
    }

    public function test_request_skipped_when_not_completed(): void
    {
        $id = $this->task(['status' => 'в работе']);
        $this->assertFalse($this->svc->requestIfNeeded($id));
        $this->assertSame('none', $this->statusOf($id));
    }

    public function test_approve_puts_expense_in_ledger(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->assertTrue($this->svc->approve($id));
        $this->assertSame('approved', $this->statusOf($id));
        $entry = (new CouncilLedgerRepository())->findByTask($id);
        $this->assertNotNull($entry);
        $this->assertSame('expense', $entry['kind']);
        $this->assertSame(1500.0, (float) $entry['amount']);
    }

    public function test_reject_removes_expense_from_ledger(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->svc->approve($id);
        $this->assertNotNull((new CouncilLedgerRepository())->findByTask($id));

        $this->assertTrue($this->svc->reject($id));
        $this->assertSame('rejected', $this->statusOf($id));
        $this->assertNull((new CouncilLedgerRepository())->findByTask($id), 'после отклонения расхода в бюджете нет');
    }

    public function test_sync_gates_on_approval(): void
    {
        $id = $this->task(['exp' => 'none']);
        $this->svc->sync($id);
        $this->assertNull((new CouncilLedgerRepository())->findByTask($id), 'неодобренный расход не попадает в бюджет');

        (new CouncilTaskRepository())->updateFields($id, ['expense_status' => 'approved'], date('Y-m-d H:i:s'));
        $this->svc->sync($id);
        $this->assertNotNull((new CouncilLedgerRepository())->findByTask($id), 'одобренный расход попадает в бюджет');
    }

    public function test_cancel_pending_resets_status_and_forgets_message(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->setMsg($id, '777', 42);

        $this->svc->cancelPending($id, 'тест');

        $this->assertSame('none', $this->statusOf($id), 'pending снят');
        $this->assertSame([null, null], $this->msgOf($id), 'сохранённое сообщение забыто');
    }

    public function test_cancel_pending_keeps_approved_status(): void
    {
        $id = $this->task(['exp' => 'approved']);
        $this->setMsg($id, '777', 42);

        $this->svc->cancelPending($id, 'тест');

        $this->assertSame('approved', $this->statusOf($id), 'одобренный статус не сбрасывается');
        $this->assertSame([null, null], $this->msgOf($id), 'сохранённое сообщение всё равно забыто');
    }

    public function test_sync_cancels_pending_when_task_reopened(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->setMsg($id, '777', 42);
        (new CouncilTaskRepository())->updateFields($id, ['status' => 'в работе'], date('Y-m-d H:i:s'));

        $this->svc->sync($id);

        $this->assertSame('none', $this->statusOf($id), 'возврат в работу снимает pending');
        $this->assertSame([null, null], $this->msgOf($id));
        $this->assertNull((new CouncilLedgerRepository())->findByTask($id));
    }

    public function test_sync_cancels_pending_when_expense_removed(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->setMsg($id, '777', 42);
        (new CouncilTaskRepository())->updateFields($id, ['spent' => 0], date('Y-m-d H:i:s'));

        $this->svc->sync($id);

        $this->assertSame('none', $this->statusOf($id), 'убранный расход снимает pending');
        $this->assertSame([null, null], $this->msgOf($id));
    }

    public function test_approve_forgets_stored_message(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->setMsg($id, '777', 42);

        $this->svc->approve($id);

        $this->assertSame('approved', $this->statusOf($id));
        $this->assertSame([null, null], $this->msgOf($id), 'после одобрения message_id забыт (webhook уже переписал сообщение)');
    }

    public function test_reject_forgets_stored_message(): void
    {
        $id = $this->task(['exp' => 'pending']);
        $this->setMsg($id, '777', 42);

        $this->svc->reject($id);

        $this->assertSame('rejected', $this->statusOf($id));
        $this->assertSame([null, null], $this->msgOf($id), 'после отклонения message_id забыт');
    }
}
