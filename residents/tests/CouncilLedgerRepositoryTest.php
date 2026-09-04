<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\CouncilCategoryRepository;

final class CouncilLedgerRepositoryTest extends TestCase
{
    private CouncilLedgerRepository $repo;
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
        $this->repo = new CouncilLedgerRepository();
    }

    public function test_create_and_find(): void
    {
        $id = $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'Сергей Ш.');
        $row = $this->repo->find($id);
        $this->assertSame('expense', $row['kind']);
        $this->assertSame(31000.0, (float) $row['amount']);
        $this->assertSame('2026-08-03', $row['entry_date']);
    }

    public function test_sums_by_month_and_months_newest_first(): void
    {
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-07-05', 'Взносы июль', 'А');
        $this->repo->create('expense', $this->roadCat,   28900.0, '2026-07-15', 'Дорога',      'А');
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы авг',  'А');
        $this->repo->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'Щиток',       'А');

        $sums = $this->repo->sumsByMonth();
        $this->assertSame(42000.0, $sums['2026-07']['income']);
        $this->assertSame(28900.0, $sums['2026-07']['expense']);
        $this->assertSame(42000.0, $sums['2026-08']['income']);
        $this->assertSame(62400.0, $sums['2026-08']['expense']);

        $this->assertSame(['2026-08', '2026-07'], $this->repo->monthsWithData()); // новые сверху
    }

    public function test_expense_by_category_desc(): void
    {
        $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'А');
        $this->repo->create('expense', $this->elecCat, 12400.0, '2026-08-28', 'Автомат', 'А');
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы', 'А'); // приход не в разбивке

        $rows = $this->repo->expenseByCategory('2026-08');
        $this->assertCount(2, $rows);
        $this->assertSame('Дороги и въезд', $rows[0]['name']); // 31000 > 12400
        $this->assertSame(31000.0, $rows[0]['sum']);
        $this->assertSame('Электрика', $rows[1]['name']);
    }

    public function test_list_for_month_has_category_name_and_date_desc(): void
    {
        $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'А');
        $this->repo->create('expense', $this->elecCat, 12400.0, '2026-08-28', 'Автомат', 'А');

        $ops = $this->repo->listForMonth('2026-08');
        $this->assertCount(2, $ops);
        $this->assertSame('2026-08-28', $ops[0]['entry_date']); // новее сверху
        $this->assertSame('Электрика', $ops[0]['category_name']);
    }

    public function test_update_and_delete(): void
    {
        $id = $this->repo->create('expense', $this->roadCat, 100.0, '2026-08-03', 'Черновик', 'А');
        $this->repo->updateFields($id, ['amount' => 555.0, 'note' => 'Исправлено']);
        $row = $this->repo->find($id);
        $this->assertSame(555.0, (float) $row['amount']);
        $this->assertSame('Исправлено', $row['note']);

        $this->repo->delete($id);
        $this->assertNull($this->repo->find($id));
    }
}
