<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilMemberRepository;

/** Привязка Telegram к ростеру совета — только по фамилии + коду администратора. */
final class CouncilClaimCodeTest extends TestCase
{
    private CouncilMemberRepository $repo;
    private int $now;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilMemberRepository();
        $this->now = strtotime('2026-09-25 12:00:00');
    }

    private function roster(string $name, string $surname): int
    {
        return $this->repo->createRosterMember($name, $surname, uniqid('m', true) . '@telegram.local', 'H');
    }

    public function test_surname_alone_is_not_enough(): void
    {
        $this->roster('Иван Петров', 'Петров');
        $this->assertNull($this->repo->claimTelegramByCode('Петров', '', 555, $this->now));
        $this->assertNull($this->repo->findByTelegramId(555));
    }

    public function test_valid_code_binds_and_is_single_use(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $code = $this->repo->issueClaimCode($id, $this->now);
        $this->assertMatchesRegularExpression('~^[A-Z2-9]{4}-[A-Z2-9]{4}$~', $code);

        // Регистр и разделители при вводе не важны.
        $member = $this->repo->claimTelegramByCode('петров', strtolower(str_replace('-', ' ', $code)), 555, $this->now);
        $this->assertNotNull($member);
        $this->assertSame($id, (int) $member['id']);
        $this->assertSame(555, (int) $this->repo->findById($id)['telegram_id']);

        // Повторно тем же кодом (другим Telegram) — нельзя.
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $code, 777, $this->now));
        $this->assertNull($this->repo->findByTelegramId(777));
    }

    public function test_code_of_other_member_does_not_match_surname(): void
    {
        $this->roster('Иван Петров', 'Петров');
        $other = $this->roster('Анна Смирнова', 'Смирнова');
        $code = $this->repo->issueClaimCode($other, $this->now);
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $code, 555, $this->now));
    }

    public function test_expired_code_rejected(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $code = $this->repo->issueClaimCode($id, $this->now);
        $later = $this->now + CouncilMemberRepository::CLAIM_CODE_TTL + 1;
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $code, 555, $later));
    }

    public function test_blocked_member_cannot_claim(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $code = $this->repo->issueClaimCode($id, $this->now);
        $this->repo->setStatus($id, 'blocked');
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $code, 555, $this->now));
    }

    public function test_new_code_invalidates_previous(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $old = $this->repo->issueClaimCode($id, $this->now);
        $new = $this->repo->issueClaimCode($id, $this->now);
        if ($old === $new) { $this->markTestSkipped('совпадение кодов'); }
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $old, 555, $this->now));
        $this->assertNotNull($this->repo->claimTelegramByCode('Петров', $new, 555, $this->now));
    }

    public function test_already_bound_member_cannot_be_reclaimed(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $code = $this->repo->issueClaimCode($id, $this->now);
        $this->repo->bindTelegram($id, 111);
        $this->assertNull($this->repo->claimTelegramByCode('Петров', $code, 555, $this->now));
        $this->assertSame(111, (int) $this->repo->findById($id)['telegram_id']);
    }

    public function test_code_is_stored_hashed(): void
    {
        $id = $this->roster('Иван Петров', 'Петров');
        $code = $this->repo->issueClaimCode($id, $this->now);
        $row = $this->repo->findById($id);
        $this->assertStringNotContainsString(str_replace('-', '', $code), (string) $row['claim_code_hash']);
        $this->assertSame(64, strlen((string) $row['claim_code_hash']));
    }
}
