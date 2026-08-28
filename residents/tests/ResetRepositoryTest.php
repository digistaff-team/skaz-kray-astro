<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{ResetRepository, FamilyRepository};

final class ResetRepositoryTest extends TestCase
{
    private ResetRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new ResetRepository();
    }

    public function test_create_and_consume_valid_token(): void
    {
        $token = $this->repo->create($this->familyId, '2999-01-01 00:00:00');
        $this->assertSame(64, strlen($token));
        $row = $this->repo->findValid($token, '2026-08-28 12:00:00');
        $this->assertSame($this->familyId, (int) $row['family_id']);
    }

    public function test_expired_token_is_invalid(): void
    {
        $token = $this->repo->create($this->familyId, '2026-08-28 10:00:00');
        $this->assertNull($this->repo->findValid($token, '2026-08-28 12:00:00'));
    }

    public function test_delete_token(): void
    {
        $token = $this->repo->create($this->familyId, '2999-01-01 00:00:00');
        $this->repo->delete($token);
        $this->assertNull($this->repo->findValid($token, '2026-08-28 12:00:00'));
    }
}
