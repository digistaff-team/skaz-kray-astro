<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilMemberRepository;

final class CouncilMemberRepositoryTest extends TestCase
{
    private CouncilMemberRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilMemberRepository();
    }

    public function test_create_active_member_and_find(): void
    {
        $id = $this->repo->create('sovet@skaz-kray.ru', 'HASH', 'Сергей Шубин');
        $m = $this->repo->findByEmail('sovet@skaz-kray.ru');
        $this->assertSame($id, (int) $m['id']);
        $this->assertSame('active', $m['status']);
        $this->assertSame('member', $m['role']);
    }

    public function test_admin_role(): void
    {
        $id = $this->repo->create('a@b.ru', 'H', 'Админ', 'admin');
        $this->assertSame('admin', $this->repo->findById($id)['role']);
    }

    public function test_block_and_update_password(): void
    {
        $id = $this->repo->create('a@b.ru', 'OLD', 'Имя');
        $this->repo->setStatus($id, 'blocked');
        $this->repo->updatePassword($id, 'NEW');
        $m = $this->repo->findById($id);
        $this->assertSame('blocked', $m['status']);
        $this->assertSame('NEW', $m['password_hash']);
    }

    public function test_email_independent_uniqueness(): void
    {
        // Тот же email, что мог бы быть у семьи — здесь это отдельная таблица, ок.
        $id = $this->repo->create('same@skaz-kray.ru', 'H', 'Член совета');
        $this->assertGreaterThan(0, $id);
        $this->assertNotNull($this->repo->findByEmail('same@skaz-kray.ru'));
    }
}
