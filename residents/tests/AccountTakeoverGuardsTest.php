<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\{Config, Csrf, Flash};
use SkazResidents\Controller\{ModerationController, ProfileController};
use SkazResidents\Repository\{FamilyRepository, HouseholdProfileRepository};

/**
 * Контроллеры, закрывающие захват чужих аккаунтов: заявка в занятое поместье
 * доступа не даёт, принимает её только владелец; редактор не трогает аккаунты
 * редакторов и админов. Вызовы контроллеров напрямую: header() в CLI — no-op,
 * Telegram не дёргается (Config без токенов).
 */
final class AccountTakeoverGuardsTest extends TestCase
{
    private \PDO $pdo;
    private HouseholdProfileRepository $hh;
    private FamilyRepository $families;

    protected function setUp(): void
    {
        Config::set([]);
        $this->pdo = make_test_db();
        $_SESSION = [];
        $_POST = [];
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role,telegram_id,max_user_id) VALUES
            (1,'admin@sk.ru','ADMINH','Админ','active','admin',NULL,NULL),
            (2,'editor@sk.ru','EDH','Редактор','active','editor',NULL,NULL),
            (7,'owner@sk.ru','OWNH','Крыловы','active','resident',111,NULL),
            (8,'other@sk.ru','OTH','Соседи','active','resident',222,NULL),
            (9,'max@sk.ru','MAXH','Кто-то из MAX','active','resident',NULL,999)");
        $this->pdo->exec("INSERT INTO households (id,glade,plot,estate_name,family_id,sort) VALUES
            (1,'(1) Обережная','5','Лукоморье',7,0),
            (2,'(2) Родная','3','Иное',8,1)");
        $this->pdo->exec("INSERT INTO residents (household_id,full_name,sort) VALUES (1,'Крылов Виталий',0)");
        $this->pdo->exec("INSERT INTO household_owners (household_id,family_id) VALUES (1,7),(2,8)");
        $this->hh = new HouseholdProfileRepository();
        $this->families = new FamilyRepository();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    private function as(int $familyId, string $role = 'resident'): void
    {
        $_SESSION = ['family_id' => $familyId, 'role' => $role, 'family_name' => 'x'];
    }

    /** @param array<string,mixed> $fields */
    private function post(array $fields): void
    {
        $_POST = $fields + ['_csrf' => Csrf::token()];
    }

    private function lastFlash(): string
    {
        $f = Flash::take();
        return $f ? (string) end($f)['type'] : '';
    }

    // --- Заявки в занятое поместье -----------------------------------------

    public function test_surname_on_occupied_household_only_files_request(): void
    {
        $this->as(9);
        $this->post(['surname' => 'Крылов']);
        (new ProfileController())->claim(['id' => '1']);

        $this->assertFalse($this->hh->isOwner(1, 9));
        $this->assertNull($this->hh->householdByFamily(9));
        $this->assertSame(1, (int) $this->hh->pendingJoinFor(9)['household_id']);
        // Прежняя автосклейка MAX-входа с владельцем больше не происходит.
        $this->assertNull($this->families->findById(7)['max_user_id']);
        $this->assertNotNull($this->families->findById(9));
    }

    public function test_wrong_surname_files_nothing(): void
    {
        $this->as(9);
        $this->post(['surname' => 'Иванов']);
        (new ProfileController())->claim(['id' => '1']);
        $this->assertNull($this->hh->pendingJoinFor(9));
    }

    private function requestId(): int
    {
        $this->hh->requestJoin(1, 9, 'Крылов');
        return (int) $this->hh->joinRequests(1)[0]['id'];
    }

    public function test_owner_approves_as_co_owner(): void
    {
        $rid = $this->requestId();
        $this->as(7);
        $this->post(['id' => $rid, 'mode' => 'owner']);
        (new ProfileController())->approveJoin();
        $this->assertTrue($this->hh->isOwner(1, 9));
        $this->assertSame([], $this->hh->joinRequests(1));
    }

    public function test_owner_of_other_household_cannot_approve(): void
    {
        $rid = $this->requestId();
        $this->as(8);   // владелец поместья 2, заявка — в поместье 1
        $this->post(['id' => $rid, 'mode' => 'owner']);
        (new ProfileController())->approveJoin();
        $this->assertFalse($this->hh->isOwner(1, 9));
        $this->assertFalse($this->hh->isOwner(2, 9));
        $this->assertSame('error', $this->lastFlash());
        $this->assertCount(1, $this->hh->joinRequests(1));
    }

    public function test_owner_merges_own_max_login(): void
    {
        $rid = $this->requestId();
        $this->as(7);
        $this->post(['id' => $rid, 'mode' => 'merge']);
        (new ProfileController())->approveJoin();
        $this->assertSame(999, (int) $this->families->findById(7)['max_user_id']);
        $this->assertNull($this->families->findById(9));
        $this->assertSame([], $this->hh->joinRequests(1));
    }

    public function test_merge_refused_when_requester_has_telegram(): void
    {
        $this->hh->requestJoin(1, 8, 'Крылов');   // у 8 есть Telegram — это другой человек
        $rid = (int) $this->hh->joinRequests(1)[0]['id'];
        $this->as(7);
        $this->post(['id' => $rid, 'mode' => 'merge']);
        (new ProfileController())->approveJoin();
        $this->assertNotNull($this->families->findById(8));
        $this->assertNull($this->families->findById(7)['max_user_id']);
        $this->assertSame('error', $this->lastFlash());
    }

    public function test_owner_rejects_request(): void
    {
        $rid = $this->requestId();
        $this->as(7);
        $this->post(['id' => $rid]);
        (new ProfileController())->rejectJoin();
        $this->assertSame([], $this->hh->joinRequests(1));
        $this->assertFalse($this->hh->isOwner(1, 9));
    }

    public function test_requester_cancels_own_request(): void
    {
        $this->requestId();
        $this->as(9);
        $this->post([]);
        (new ProfileController())->cancelJoin();
        $this->assertNull($this->hh->pendingJoinFor(9));
    }

    // --- Права модератора ---------------------------------------------------

    public function test_editor_cannot_reset_admin_password(): void
    {
        $this->as(2, 'editor');
        $this->post(['id' => 1]);
        (new ModerationController())->resetPassword();
        $this->assertSame('ADMINH', $this->families->findById(1)['password_hash']);
        $this->assertSame('error', $this->lastFlash());
    }

    public function test_editor_cannot_block_editor(): void
    {
        $this->as(2, 'editor');
        $this->post(['id' => 2]);
        (new ModerationController())->rejectFamily();
        $this->assertSame('active', $this->families->findById(2)['status']);
    }

    public function test_editor_can_reset_resident_password(): void
    {
        $this->as(2, 'editor');
        $this->post(['id' => 7]);
        (new ModerationController())->resetPassword();
        $this->assertNotSame('OWNH', $this->families->findById(7)['password_hash']);
        $this->assertSame('success', $this->lastFlash());
    }

    public function test_admin_can_reset_editor_password(): void
    {
        $this->as(1, 'admin');
        $this->post(['id' => 2]);
        (new ModerationController())->resetPassword();
        $this->assertNotSame('EDH', $this->families->findById(2)['password_hash']);
    }
}
