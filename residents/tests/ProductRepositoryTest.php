<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\ProductRepository;
use SkazResidents\Repository\FamilyRepository;

final class ProductRepositoryTest extends TestCase
{
    private ProductRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new ProductRepository();
    }

    public function test_create_is_pending_with_nullable_price(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'Липовый', null, 'тел 8-900', '2026-08-28 09:00:00');
        $p = $this->repo->findById($id);
        $this->assertSame('pending', $p['status']);
        $this->assertNull($p['price']);
        $this->assertSame('тел 8-900', $p['contact']);
    }

    public function test_unit_is_saved_and_updated(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'Липовый', '500 ₽', 'C', '2026-08-28 09:00:00', 'residents', 'кг.');
        $this->assertSame('кг.', $this->repo->findById($id)['unit']);

        $this->repo->update($id, 'Мёд', 'Липовый', '600 ₽', 'C', '2026-08-29 09:00:00', 'residents', 'банка');
        $this->assertSame('банка', $this->repo->findById($id)['unit']);

        // Цену убрали — единица тоже уходит (так её передаёт контроллер).
        $this->repo->update($id, 'Мёд', 'Липовый', null, 'C', '2026-08-30 09:00:00', 'residents', null);
        $this->assertNull($this->repo->findById($id)['unit']);
    }

    public function test_find_recent_by_title_catches_repeat_submit(): void
    {
        $this->repo->create($this->familyId, 'Овощи', 'D', null, 'C', '2026-08-28 09:00:00', 'residents');

        // Повторная отправка через полминуты — находим уже созданный товар.
        $this->assertNotNull($this->repo->findRecentByTitle($this->familyId, 'Овощи', '2026-08-28 08:58:00'));
        // Спустя две минуты это уже осознанное второе размещение — не мешаем.
        $this->assertNull($this->repo->findRecentByTitle($this->familyId, 'Овощи', '2026-08-28 09:02:00'));
        // Чужой товар с таким же названием не считается дублем.
        $this->assertNull($this->repo->findRecentByTitle($this->familyId + 1, 'Овощи', '2026-08-28 08:58:00'));
    }

    public function test_approve_publishes(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $this->assertSame('published', $this->repo->findById($id)['status']);
    }

    public function test_edit_returns_to_pending(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $this->repo->update($id, 'Мёд 2', 'D2', null, 'C2', '2026-08-28 11:00:00');
        $this->assertSame('pending', $this->repo->findById($id)['status']);
    }

    public function test_list_published_has_family_name(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $rows = $this->repo->listPublished(10, 0);
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('family_name', $rows[0]);
    }

    public function test_residents_published_immediately(): void
    {
        $id = $this->repo->create($this->familyId, 'Саженцы', 'D', null, 'C', '2026-08-28 09:00:00', 'residents');
        $p = $this->repo->findById($id);
        $this->assertSame('published', $p['status']); // «только соседи» — сразу
        $this->assertSame('residents', $p['visibility']);
        $this->assertSame('2026-08-28 09:00:00', $p['published_at']);
    }

    public function test_list_available_has_both_visibilities_external_only_public(): void
    {
        $this->repo->create($this->familyId, 'Соседям', 'D', null, 'C', '2026-08-28 09:00:00', 'residents'); // published
        $pub = $this->repo->create($this->familyId, 'На сайт', 'D', null, 'C', '2026-08-28 09:30:00', 'public'); // pending
        $this->repo->approve($pub, '2026-08-28 10:00:00'); // published
        $this->repo->create($this->familyId, 'Черновик', 'D', null, 'C', '2026-08-28 09:45:00', 'public'); // pending

        $this->assertCount(2, $this->repo->listAvailable(10, 0)); // рынок: residents + одобренный public
        $this->assertCount(1, $this->repo->listPublished(10, 0)); // внешняя лента: только public
    }
}
