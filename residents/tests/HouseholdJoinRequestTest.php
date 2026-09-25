<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\HouseholdProfileRepository;

/**
 * Присоединение к занятому поместью — только заявкой, которую принимает
 * владелец: сама по себе фамилия доступа к поместью не даёт.
 */
final class HouseholdJoinRequestTest extends TestCase
{
    private HouseholdProfileRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role,telegram_id,max_user_id) VALUES
            (7,'owner@sk.ru','H','Крыловы','active','resident',111,NULL),
            (9,'guest@sk.ru','H','Кто-то','active','resident',NULL,999)");
        $this->pdo->exec("INSERT INTO households (id,glade,plot,estate_name,family_id,sort) VALUES
            (1,'(1) Обережная','5','Лукоморье',7,0),
            (2,'(2) Родная','3','',NULL,1)");
        $this->pdo->exec("INSERT INTO residents (household_id,full_name,sort) VALUES (1,'Крылов Виталий',0)");
        $this->pdo->exec("INSERT INTO household_owners (household_id,family_id) VALUES (1,7)");
        $this->repo = new HouseholdProfileRepository();
    }

    public function test_request_does_not_grant_access(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $this->assertNull($this->repo->householdByFamily(9));
        $this->assertFalse($this->repo->isOwner(1, 9));
        $pending = $this->repo->pendingJoinFor(9);
        $this->assertSame(1, (int) $pending['household_id']);
        $this->assertSame('Лукоморье', $pending['estate_name']);
    }

    public function test_owner_sees_request_with_login_platform(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $reqs = $this->repo->joinRequests(1);
        $this->assertCount(1, $reqs);
        $this->assertSame('Кто-то', $reqs[0]['name']);
        $this->assertSame(999, (int) $reqs[0]['max_user_id']);
        $this->assertSame([], $this->repo->joinRequests(2));
    }

    public function test_new_request_replaces_previous(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $this->repo->requestJoin(2, 9, '');
        $this->assertSame([], $this->repo->joinRequests(1));
        $this->assertSame(2, (int) $this->repo->pendingJoinFor(9)['household_id']);
    }

    public function test_approval_makes_owner_and_clears_request(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $this->repo->joinAsOwner(1, 9);
        $this->assertTrue($this->repo->isOwner(1, 9));
        $this->assertNull($this->repo->pendingJoinFor(9));
        $this->assertSame([], $this->repo->joinRequests(1));
        // Первичный владелец не меняется.
        $this->assertSame(7, (int) $this->repo->householdById(1)['family_id']);
    }

    public function test_claiming_free_household_drops_pending_request(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $this->assertTrue($this->repo->claim(2, 9));
        $this->assertNull($this->repo->pendingJoinFor(9));
    }

    public function test_reject_and_cancel(): void
    {
        $this->repo->requestJoin(1, 9, 'Крылов');
        $id = (int) $this->repo->joinRequests(1)[0]['id'];
        $this->assertSame(1, (int) $this->repo->joinRequestById($id)['household_id']);
        $this->repo->deleteJoinRequest($id);
        $this->assertNull($this->repo->joinRequestById($id));

        $this->repo->requestJoin(1, 9, 'Крылов');
        $this->repo->cancelJoinRequestOf(9);
        $this->assertNull($this->repo->pendingJoinFor(9));
    }
}
