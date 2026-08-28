<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\DiaryRepository;
use SkazResidents\Repository\FamilyRepository;

final class DiaryRepositoryTest extends TestCase
{
    private DiaryRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new DiaryRepository();
    }

    public function test_create_is_pending(): void
    {
        $id = $this->repo->create($this->familyId, 'Весна', 'Посадили сад', '2026-08-28 09:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['published_at']);
    }

    public function test_approve_publishes(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('published', $e['status']);
        $this->assertSame('2026-08-28 10:00:00', $e['published_at']);
    }

    public function test_reject_stores_reason(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $e = $this->repo->findById($id);
        $this->assertSame('rejected', $e['status']);
        $this->assertSame('Нет фото', $e['reject_reason']);
    }

    public function test_edit_returns_to_pending_and_clears_reason(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $this->repo->update($id, 'T2', 'B2', '2026-08-28 11:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['reject_reason']);
        $this->assertSame('T2', $e['title']);
    }

    public function test_list_published_only(): void
    {
        $p = $this->repo->create($this->familyId, 'Опубл', 'B', '2026-08-28 09:00:00');
        $this->repo->approve($p, '2026-08-28 10:00:00');
        $this->repo->create($this->familyId, 'Черновик', 'B', '2026-08-28 09:30:00');
        $rows = $this->repo->listPublished(10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Опубл', $rows[0]['title']);
        $this->assertArrayHasKey('family_name', $rows[0]); // джойн имени семьи
    }

    public function test_list_by_family(): void
    {
        $this->repo->create($this->familyId, 'Моя', 'B', '2026-08-28 09:00:00');
        $rows = $this->repo->listByFamily($this->familyId);
        $this->assertCount(1, $rows);
    }

    public function test_delete(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }
}
