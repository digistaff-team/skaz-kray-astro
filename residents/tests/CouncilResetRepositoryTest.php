<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{CouncilResetRepository, CouncilMemberRepository};

final class CouncilResetRepositoryTest extends TestCase
{
    private CouncilResetRepository $resets;
    private int $memberId;

    protected function setUp(): void
    {
        make_test_db();
        $this->memberId = (new CouncilMemberRepository())->create('m@skaz-kray.ru', 'H', 'Имя');
        $this->resets = new CouncilResetRepository();
    }

    public function test_create_and_find_valid(): void
    {
        $token = $this->resets->create($this->memberId, '2999-01-01 00:00:00');
        $row = $this->resets->findValid($token, '2026-08-28 12:00:00');
        $this->assertNotNull($row);
        $this->assertSame($this->memberId, (int) $row['member_id']);
    }

    public function test_expired_token_invalid(): void
    {
        $token = $this->resets->create($this->memberId, '2020-01-01 00:00:00');
        $this->assertNull($this->resets->findValid($token, '2026-08-28 12:00:00'));
    }

    public function test_delete(): void
    {
        $token = $this->resets->create($this->memberId, '2999-01-01 00:00:00');
        $this->resets->delete($token);
        $this->assertNull($this->resets->findValid($token, '2026-08-28 12:00:00'));
    }
}
