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
        $id = $this->repo->create($this->familyId, 'Весна', 'Посадили сад', 'residents', '2026-08-28 09:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['published_at']);
        $this->assertSame(0, (int) $e['is_public']);
    }

    public function test_create_public(): void
    {
        $id = $this->repo->create($this->familyId, 'Весна', 'Посадили сад', 'public', '2026-08-28 09:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame(1, (int) $e['is_public']);
    }

    public function test_approve_publishes(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', 'residents', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('published', $e['status']);
        $this->assertSame('2026-08-28 10:00:00', $e['published_at']);
    }

    public function test_reject_stores_reason(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', 'residents', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $e = $this->repo->findById($id);
        $this->assertSame('rejected', $e['status']);
        $this->assertSame('Нет фото', $e['reject_reason']);
    }

    public function test_edit_returns_to_pending_and_updates_is_public(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', 'residents', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $this->repo->update($id, 'T2', 'B2', 'public', '2026-08-28 11:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['reject_reason']);
        $this->assertSame('T2', $e['title']);
        $this->assertSame(1, (int) $e['is_public']);
    }

    public function test_list_published_only(): void
    {
        $p = $this->repo->create($this->familyId, 'Опубл', 'B', 'residents', '2026-08-28 09:00:00');
        $this->repo->approve($p, '2026-08-28 10:00:00');
        $this->repo->create($this->familyId, 'Черновик', 'B', 'residents', '2026-08-28 09:30:00');
        $rows = $this->repo->listPublished(10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Опубл', $rows[0]['title']);
        $this->assertArrayHasKey('family_name', $rows[0]); // джойн имени семьи
    }

    public function test_list_by_family(): void
    {
        $this->repo->create($this->familyId, 'Моя', 'B', 'residents', '2026-08-28 09:00:00');
        $rows = $this->repo->listByFamily($this->familyId);
        $this->assertCount(1, $rows);
    }

    public function test_public_feed_only_includes_published_and_public(): void
    {
        $public  = $this->repo->create($this->familyId, 'Публичная', 'B', 'public', '2026-08-28 09:00:00');
        $private = $this->repo->create($this->familyId, 'Приватная', 'B', 'residents', '2026-08-28 09:05:00');
        $draft   = $this->repo->create($this->familyId, 'Черновик', 'B', 'public', '2026-08-28 09:10:00'); // с галочкой, но не одобрена
        $this->repo->approve($public, '2026-08-28 10:00:00');
        $this->repo->approve($private, '2026-08-28 10:05:00');

        // Внутренняя лента (все опубликованные) — 2 записи (public+private), draft не одобрена.
        $this->assertCount(2, $this->repo->listPublished(10, 0));

        // Внешняя публичная лента — только 1 (опубликована И с галочкой).
        $rows = $this->repo->listPublishedPublic(10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Публичная', $rows[0]['title']);
        $this->assertSame(1, $this->repo->countPublishedPublic());

        $this->assertNotNull($this->repo->findPublishedPublicById($public));
        $this->assertNull($this->repo->findPublishedPublicById($private)); // без галочки — не видна вовне
        $this->assertNull($this->repo->findPublishedPublicById($draft));   // не одобрена — не видна вовне
    }

    public function test_delete(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', 'residents', '2026-08-28 09:00:00');
        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }

    public function test_private_auto_published_and_hidden_from_feed(): void
    {
        $id = $this->repo->create($this->familyId, 'Личное', 'B', 'private', '2026-08-28 09:00:00');
        $e = $this->repo->findById($id);
        // Публикуется сразу, без модерации.
        $this->assertSame('published', $e['status']);
        $this->assertSame('2026-08-28 09:00:00', $e['published_at']);
        $this->assertSame('private', $e['visibility']);
        $this->assertSame(0, (int) $e['is_public']);
        // Нет в общей внутренней ленте, но есть в списке своей семьи.
        $this->assertCount(0, $this->repo->listPublished(10, 0));
        $this->assertNull($this->repo->findPublishedById($id));
        $this->assertCount(1, $this->repo->listByFamily($this->familyId));
    }
}
