<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\FamilyRepository;

final class FamilyRepositoryTest extends TestCase
{
    private FamilyRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new FamilyRepository();
    }

    public function test_create_pending_and_find_by_email(): void
    {
        $id = $this->repo->createPending('semya@skaz-kray.ru', 'HASH', 'Поместье Ивановых');
        $f = $this->repo->findByEmail('semya@skaz-kray.ru');
        $this->assertSame($id, (int) $f['id']);
        $this->assertSame('pending', $f['status']);
        $this->assertSame('resident', $f['role']);
    }

    public function test_approve_sets_active(): void
    {
        $id = $this->repo->createPending('a@b.ru', 'H', 'Дом');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $f = $this->repo->findById($id);
        $this->assertSame('active', $f['status']);
        $this->assertSame('2026-08-28 10:00:00', $f['approved_at']);
    }

    public function test_list_pending(): void
    {
        $this->repo->createPending('a@b.ru', 'H', 'Дом А');
        $id = $this->repo->createPending('c@d.ru', 'H', 'Дом B');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $pending = $this->repo->listByStatus('pending');
        $this->assertCount(1, $pending);
        $this->assertSame('Дом А', $pending[0]['name']);
    }

    public function test_update_password(): void
    {
        $id = $this->repo->createPending('a@b.ru', 'OLD', 'Дом');
        $this->repo->updatePassword($id, 'NEW');
        $this->assertSame('NEW', $this->repo->findById($id)['password_hash']);
    }
}
