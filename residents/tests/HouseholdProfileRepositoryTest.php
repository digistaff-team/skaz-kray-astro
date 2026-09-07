<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\HouseholdProfileRepository;

final class HouseholdProfileRepositoryTest extends TestCase
{
    private HouseholdProfileRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        // Аккаунт-семья (family_id=7) привязан к поместью id=1; ещё одно поместье без привязки.
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role) VALUES
            (7,'semya@sk.ru','H','Поместье','active','resident')");
        // family 8 — неактивный (placeholder) аккаунт импорта.
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role) VALUES
            (8,'pend@sk.ru','H','Плейсхолдер','pending','resident')");
        $this->pdo->exec("INSERT INTO households (id,glade,plot,estate_name,family_id,sort) VALUES
            (1,'(1) Обережная','5','Лукоморье',7,0),
            (2,'(2) Родная','3','Иное',NULL,1),
            (3,'(3) Ягодная','1','Плейсхолдер',8,2)");
        $this->pdo->exec("INSERT INTO residents (household_id,full_name,sort) VALUES
            (1,'Крылов Виталий',0),(1,'Крылова Юлия',1),(2,'Чужой Человек',0)");
        // Совладельцы (как их создаёт бэкфилл миграции из households.family_id).
        $this->pdo->exec("INSERT INTO household_owners (household_id,family_id) VALUES (1,7),(3,8)");
        $this->repo = new HouseholdProfileRepository();
    }

    private function now(): string { return '01.01.2026'; }

    public function test_household_by_family(): void
    {
        $h = $this->repo->householdByFamily(7);
        $this->assertNotNull($h);
        $this->assertSame('Лукоморье', $h['estate_name']);
        $this->assertNull($this->repo->householdByFamily(999));
    }

    public function test_members_only_of_household(): void
    {
        $this->assertCount(2, $this->repo->members(1));
        $this->assertCount(1, $this->repo->members(2));
    }

    public function test_add_update_delete_member(): void
    {
        $id = $this->repo->addMember(1, [
            'full_name' => 'Крылов Владислав', 'birth_raw' => '25.07.2014', 'birth_date' => '2014-07-25',
            'phone' => '', 'vk' => '', 'skills' => null, 'community_role' => null, 'moved_text' => '',
            'residence' => '', 'hometown' => 'Краснодар', 'email' => '', 'comment' => null,
        ], $this->now());
        $this->assertCount(3, $this->repo->members(1));
        $m = $this->repo->memberById($id);
        $this->assertSame('Краснодар', $m['hometown']);
        $this->assertSame('2014-07-25', $m['birth_date']);

        $this->repo->updateMember($id, [
            'full_name' => 'Крылов Владислав', 'birth_raw' => '', 'birth_date' => null,
            'phone' => '89001234567', 'vk' => '', 'skills' => 'футбол', 'community_role' => null,
            'moved_text' => '', 'residence' => '', 'hometown' => '', 'email' => 'v@sk.ru', 'comment' => null,
        ], $this->now());
        $m = $this->repo->memberById($id);
        $this->assertSame('89001234567', $m['phone']);
        $this->assertSame('футбол', $m['skills']);

        $this->repo->deleteMember($id);
        $this->assertCount(2, $this->repo->members(1));
    }

    public function test_car_crud(): void
    {
        $id = $this->repo->addCar(1, 'Рено Логан, белый', 'А123ВС', 'зимняя резина');
        $cars = $this->repo->cars(1);
        $this->assertCount(1, $cars);
        $this->assertSame('Рено Логан, белый', $cars[0]['title']);

        $this->repo->updateCar($id, 'Рено Логан, синий', 'А123ВС 93', '');
        $this->assertSame('Рено Логан, синий', $this->repo->carById($id)['title']);
        $this->assertSame('А123ВС 93', $this->repo->carById($id)['plate']);

        $this->repo->deleteCar($id);
        $this->assertCount(0, $this->repo->cars(1));
    }

    public function test_cars_for_households_grouping(): void
    {
        $this->repo->addCar(1, 'Авто A', '', '');
        $this->repo->addCar(2, 'Авто B', '', '');
        $map = $this->repo->carsForHouseholds([1, 2]);
        $this->assertCount(1, $map[1]);
        $this->assertCount(1, $map[2]);
        $this->assertSame('Авто A', $map[1][0]['title']);
    }

    public function test_pet_crud(): void
    {
        $id = $this->repo->addPet(1, 'Барсик', 'кошка', 'рыжий');
        $pets = $this->repo->pets(1);
        $this->assertCount(1, $pets);
        $this->assertSame('Барсик', $pets[0]['name']);
        $this->assertSame('кошка', $pets[0]['kind']);

        $this->repo->updatePet($id, 'Барсик', 'кот', 'рыжий, пушистый');
        $this->assertSame('кот', $this->repo->petById($id)['kind']);

        $this->repo->deletePet($id);
        $this->assertCount(0, $this->repo->pets(1));
    }

    public function test_claimable_lists_free_and_placeholder_only(): void
    {
        $list = $this->repo->listClaimable();
        $ids = array_map(static fn($h) => (int) $h['id'], $list);
        sort($ids);
        $this->assertSame([2, 3], $ids); // 1 привязан к активному — не в списке
    }

    public function test_is_claimable(): void
    {
        $this->assertFalse($this->repo->isClaimable(1)); // активная привязка
        $this->assertTrue($this->repo->isClaimable(2));  // свободно
        $this->assertTrue($this->repo->isClaimable(3));  // placeholder (pending)
    }

    public function test_surname_matches_household(): void
    {
        $this->assertTrue($this->repo->surnameMatchesHousehold(1, 'Крылов'));
        $this->assertTrue($this->repo->surnameMatchesHousehold(1, 'крылов')); // регистронезависимо
        $this->assertFalse($this->repo->surnameMatchesHousehold(1, 'Иванов'));
        $this->assertTrue($this->repo->surnameMatchesHousehold(2, 'Чужой'));
        $this->assertFalse($this->repo->surnameMatchesHousehold(1, ''));
    }

    public function test_claim_links_and_moves_account(): void
    {
        // family 7 уже привязан к 1; заявляет поместье 2 — 1 освобождается, 2 привязывается.
        $this->assertTrue($this->repo->claim(2, 7));
        $this->assertSame(2, (int) $this->repo->householdByFamily(7)['id']);
        $this->assertNull($this->repo->householdById(1)['family_id']);
    }

    public function test_claim_rejects_active_household(): void
    {
        $this->assertFalse($this->repo->claim(1, 8)); // 1 привязан к активному
    }

    public function test_update_estate_name(): void
    {
        $this->repo->updateHouseholdEstate(1, 'Новое имя');
        $this->assertSame('Новое имя', $this->repo->householdById(1)['estate_name']);
    }

    // ── Совместное владение ─────────────────────────────────────────────────

    private function addActiveFamily(int $id): void
    {
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role) VALUES
            ($id,'f$id@sk.ru','H','Аккаунт $id','active','resident')");
    }

    public function test_claim_creates_owner_row(): void
    {
        // family 7 (владелец 1) заявляет свободное поместье 2.
        $this->assertTrue($this->repo->claim(2, 7));
        $this->assertSame(2, (int) $this->repo->householdByFamily(7)['id']); // резолв через owners
        $this->assertTrue($this->repo->isOwner(2, 7));
        $this->assertFalse($this->repo->isOwner(1, 7));                       // покинул прежнее
        $this->assertCount(1, $this->repo->owners(2));
    }

    public function test_join_as_owner_adds_coowner_keeping_primary(): void
    {
        $this->addActiveFamily(9);
        $this->repo->joinAsOwner(1, 9);                       // 9 присоединяется к занятому 1
        $this->assertTrue($this->repo->isOwner(1, 9));
        $this->assertSame(1, (int) $this->repo->householdByFamily(9)['id']);
        $this->assertSame(7, (int) $this->repo->householdById(1)['family_id']); // первичный не изменился
        $owners = $this->repo->owners(1);
        $this->assertCount(2, $owners);
        $this->assertSame(1, (int) $owners[0]['is_primary']);  // первичный — первым
        $this->assertSame(7, (int) $owners[0]['family_id']);
    }

    public function test_join_moves_account_from_previous_home(): void
    {
        // family 8 владел 3; присоединяется совладельцем к 1 — 3 освобождается.
        $this->repo->joinAsOwner(1, 8);
        $this->assertSame(1, (int) $this->repo->householdByFamily(8)['id']);
        $this->assertFalse($this->repo->isOwner(3, 8));
        $this->assertNull($this->repo->householdById(3)['family_id']);
    }

    public function test_remove_coowner(): void
    {
        $this->addActiveFamily(9);
        $this->repo->joinAsOwner(1, 9);
        $this->repo->removeOwner(1, 9);
        $this->assertFalse($this->repo->isOwner(1, 9));
        $this->assertNull($this->repo->householdByFamily(9));
        $this->assertSame(7, (int) $this->repo->householdById(1)['family_id']); // первичный цел
    }

    public function test_remove_primary_promotes_next(): void
    {
        $this->addActiveFamily(9);
        $this->repo->joinAsOwner(1, 9);
        $this->repo->removeOwner(1, 7);                        // убрали первичного
        $this->assertFalse($this->repo->isOwner(1, 7));
        $this->assertSame(9, (int) $this->repo->householdById(1)['family_id']); // 9 стал первичным
        $this->assertSame(1, (int) $this->repo->householdByFamily(9)['id']);
    }

    public function test_remove_last_owner_clears_primary(): void
    {
        $this->repo->removeOwner(1, 7);                        // единственный владелец
        $this->assertCount(0, $this->repo->owners(1));
        $this->assertNull($this->repo->householdById(1)['family_id']);
    }
}
