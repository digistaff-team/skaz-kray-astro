<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilCategoryRepository;

final class CouncilCategoryRepositoryTest extends TestCase
{
    private CouncilCategoryRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilCategoryRepository();
    }

    public function test_create_and_list_by_kind_ordered_by_position(): void
    {
        $this->repo->create('income', 'Аренда');
        $this->repo->create('income', 'Школа');
        $this->repo->create('expense', 'Дороги');

        $income = $this->repo->listByKind('income', true);
        $this->assertCount(2, $income);
        $this->assertSame('Аренда', $income[0]['name']); // position 0 раньше 1
        $this->assertSame('Школа', $income[1]['name']);

        $this->assertCount(1, $this->repo->listByKind('expense', true));
    }

    public function test_archive_hides_from_active_but_kept_in_full(): void
    {
        $id = $this->repo->create('expense', 'Праздники');
        $this->repo->setActive($id, false);

        $this->assertCount(0, $this->repo->listByKind('expense', true));
        $this->assertCount(1, $this->repo->listByKind('expense', false));

        $this->repo->setActive($id, true);
        $this->assertCount(1, $this->repo->listByKind('expense', true));
    }

    public function test_rename(): void
    {
        $id = $this->repo->create('income', 'Старое');
        $this->repo->rename($id, 'Новое');
        $this->assertSame('Новое', $this->repo->find($id)['name']);
    }
}
