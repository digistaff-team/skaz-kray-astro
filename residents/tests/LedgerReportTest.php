<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\LedgerReport;
use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\CouncilCategoryRepository;
use SkazResidents\Repository\ImageRepository;

final class LedgerReportTest extends TestCase
{
    private CouncilLedgerRepository $ledger;
    private int $incomeCat;
    private int $roadCat;
    private int $elecCat;

    protected function setUp(): void
    {
        make_test_db();
        $cats = new CouncilCategoryRepository();
        $this->incomeCat = $cats->create('income', 'Из Фонда общего дома');
        $this->roadCat   = $cats->create('expense', 'Дороги и въезд');
        $this->elecCat   = $cats->create('expense', 'Электрика');
        $this->ledger = new CouncilLedgerRepository();
    }

    private function report(): LedgerReport
    {
        return new LedgerReport($this->ledger, new ImageRepository());
    }

    public function test_month_balance_can_be_negative(): void
    {
        // Август: собрано 42000, потрачено 62400 → остаток −20400
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы', 'А');
        $this->ledger->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'Щиток',  'А');

        $r = $this->report()->build();
        $aug = $r['months'][0];
        $this->assertSame('2026-08', $aug['ym']);
        $this->assertSame(42000.0, $aug['income']);
        $this->assertSame(62400.0, $aug['expense']);
        $this->assertSame(-20400.0, $aug['balance']);
    }

    public function test_totals_all_time(): void
    {
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-07-05', 'a', 'А');
        $this->ledger->create('expense', $this->roadCat,   10000.0, '2026-07-15', 'b', 'А');
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'c', 'А');

        $r = $this->report()->build();
        $this->assertSame(84000.0, $r['totalIncome']);
        $this->assertSame(10000.0, $r['totalExpense']);
        $this->assertSame(74000.0, $r['totalBalance']);
    }

    public function test_default_selected_month_is_latest_with_breakdown_pct(): void
    {
        $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        $this->ledger->create('expense', $this->elecCat, 10000.0, '2026-08-28', 'свет',   'А');

        $r = $this->report()->build();
        $this->assertSame('2026-08', $r['selectedYm']);
        $this->assertSame('Август 2026', $r['selectedLabel']);
        // 30000 из 40000 = 75%
        $this->assertSame('Дороги и въезд', $r['breakdown'][0]['name']);
        $this->assertSame(75, $r['breakdown'][0]['pct']);
    }

    public function test_operations_carry_receipt_flag(): void
    {
        $exp = $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        $this->ledger->create('income', $this->incomeCat, 42000.0, '2026-08-05', 'взносы', 'А');
        (new ImageRepository())->add('expense', $exp, 'receipt.jpg', 0);

        $r = $this->report()->build('2026-08');
        $byId = [];
        foreach ($r['operations'] as $op) { $byId[$op['id']] = $op; }
        $this->assertTrue($byId[$exp]['hasReceipt']);
    }

    public function test_empty_state_does_not_crash(): void
    {
        $r = $this->report()->build();
        $this->assertSame([], $r['months']);
        $this->assertNull($r['selectedYm']);
        $this->assertSame([], $r['operations']);
        $this->assertSame(0.0, $r['totalBalance']);
    }

    public function test_selected_month_tiles(): void
    {
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'взносы', 'А');
        $this->ledger->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'щиток',  'А');

        $r = $this->report()->build('2026-08');
        $this->assertSame(42000.0, $r['monthIncome']);
        $this->assertSame(62400.0, $r['monthExpense']);
        $this->assertSame(-20400.0, $r['monthBalance']);
    }

    public function test_operation_carries_receipt_path(): void
    {
        $exp = $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        (new ImageRepository())->add('expense', $exp, 'abc123.jpg', 0);

        $r = $this->report()->build('2026-08');
        $op = $r['operations'][0];
        $this->assertTrue($op['hasReceipt']);
        $this->assertSame('abc123.jpg', $op['receiptPath']);
    }
}
