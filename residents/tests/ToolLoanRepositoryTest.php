<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{ToolLoanRepository, ToolRepository, FamilyRepository};

final class ToolLoanRepositoryTest extends TestCase
{
    private ToolLoanRepository $loans;
    private ToolRepository $tools;
    private int $owner;
    private int $borrower;
    private int $toolId;

    protected function setUp(): void
    {
        make_test_db();
        $fam = new FamilyRepository();
        $this->owner    = $fam->createPending('owner@skaz-kray.ru', 'H', 'Владелец');
        $this->borrower = $fam->createPending('borrower@skaz-kray.ru', 'H', 'Заёмщик');
        $this->tools = new ToolRepository();
        $this->toolId = $this->tools->create($this->owner, 'Бетономешалка', 'Строительный', null, null, null, '2026-08-29 10:00:00');
        $this->loans = new ToolLoanRepository();
    }

    public function test_request_creates_active_loan(): void
    {
        $id = $this->loans->create($this->toolId, $this->borrower, 'нужна на выходные', '2026-09-05', '2026-08-29 10:00:00');
        $l = $this->loans->findById($id);
        $this->assertSame('requested', $l['status']);
        $active = $this->loans->activeForTool($this->toolId);
        $this->assertSame($id, (int) $active['id']);
        $this->assertSame('Заёмщик', $active['borrower_name']);
    }

    public function test_give_then_return_ok_clears_active(): void
    {
        $id = $this->loans->create($this->toolId, $this->borrower, null, null, '2026-08-29 10:00:00');
        $this->loans->give($id, '2026-08-29 11:00:00');
        $this->assertSame('on_loan', $this->loans->findById($id)['status']);
        $this->assertNotNull($this->loans->activeForTool($this->toolId));

        $this->loans->markReturned($id, 'ok', 'спасибо', '2026-08-30 12:00:00');
        $l = $this->loans->findById($id);
        $this->assertSame('returned', $l['status']);
        $this->assertSame('ok', $l['return_condition']);
        $this->assertNull($this->loans->activeForTool($this->toolId)); // активных нет
    }

    public function test_decline_and_cancel(): void
    {
        $d = $this->loans->create($this->toolId, $this->borrower, null, null, '2026-08-29 10:00:00');
        $this->loans->decline($d, '2026-08-29 11:00:00');
        $this->assertSame('declined', $this->loans->findById($d)['status']);
        $this->assertNull($this->loans->activeForTool($this->toolId));

        $c = $this->loans->create($this->toolId, $this->borrower, null, null, '2026-08-29 12:00:00');
        $this->loans->cancel($c);
        $this->assertSame('cancelled', $this->loans->findById($c)['status']);
    }

    public function test_detailed_join_has_owner_and_borrower(): void
    {
        $id = $this->loans->create($this->toolId, $this->borrower, null, null, '2026-08-29 10:00:00');
        $d = $this->loans->findDetailed($id);
        $this->assertSame('Бетономешалка', $d['tool_name']);
        $this->assertSame($this->owner, (int) $d['owner_id']);
        $this->assertSame('Владелец', $d['owner_name']);
        $this->assertSame('Заёмщик', $d['borrower_name']);
        $this->assertSame('borrower@skaz-kray.ru', $d['borrower_email']);
    }

    public function test_incoming_and_borrowings_and_history(): void
    {
        $id = $this->loans->create($this->toolId, $this->borrower, null, null, '2026-08-29 10:00:00');
        $this->assertCount(1, $this->loans->listIncoming($this->owner, ['requested']));
        $this->assertCount(1, $this->loans->listByBorrower($this->borrower));

        $this->loans->give($id, '2026-08-29 11:00:00');
        $this->loans->markReturned($id, 'ok', null, '2026-08-30 10:00:00');
        $hist = $this->loans->historyForTool($this->toolId);
        $this->assertCount(1, $hist);
        $this->assertSame('returned', $hist[0]['status']);
    }
}
