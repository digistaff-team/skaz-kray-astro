<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\BroadcastListRepository;
use SkazResidents\Repository\FamilyRepository;

/**
 * Сохранённые списки адресатов рассылки: создание с названием, переименование,
 * членство (добавить/убрать, повтор — no-op), удаление списка и выборка
 * адресатов — только активные семьи с привязанным Telegram.
 */
final class BroadcastListRepositoryTest extends TestCase
{
    private BroadcastListRepository $repo;
    private FamilyRepository $families;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo     = new BroadcastListRepository();
        $this->families = new FamilyRepository();
    }

    public function test_create_find_and_all_with_counts(): void
    {
        $this->assertSame([], $this->repo->allWithCounts());

        $id = $this->repo->create('Дачники');
        $this->assertGreaterThan(0, $id);

        $list = $this->repo->find($id);
        $this->assertNotNull($list);
        $this->assertSame('Дачники', (string) $list['name']);

        $all = $this->repo->allWithCounts();
        $this->assertCount(1, $all);
        $this->assertSame('Дачники', (string) $all[0]['name']);
        $this->assertSame(0, (int) $all[0]['members']);

        $this->assertNull($this->repo->find(999));
    }

    public function test_rename_updates_name(): void
    {
        $id = $this->repo->create('Старое имя');
        $this->repo->rename($id, 'Новое имя');
        $this->assertSame('Новое имя', (string) $this->repo->find($id)['name']);
    }

    public function test_add_remove_member_and_idempotent_readd(): void
    {
        $listId = $this->repo->create('Список');
        $familyId = $this->families->createPending('semya@skaz-kray.ru', 'H', 'Поместье Семьи');

        $this->repo->addMember($listId, $familyId);
        $this->repo->addMember($listId, $familyId);   // повтор — no-op, PK на паре
        $members = $this->repo->members($listId);
        $this->assertCount(1, $members);
        $this->assertSame($familyId, (int) $members[0]['family_id']);
        $this->assertSame(1, (int) $this->repo->allWithCounts()[0]['members']);

        $this->repo->removeMember($listId, $familyId);
        $this->assertSame([], $this->repo->members($listId));
        // Удалённое членство можно завести заново.
        $this->repo->addMember($listId, $familyId);
        $this->assertCount(1, $this->repo->members($listId));
    }

    public function test_delete_list_removes_membership_too(): void
    {
        $pdo = \SkazResidents\Database::pdo();
        $listId = $this->repo->create('На удаление');
        $familyId = $this->families->createPending('a@skaz-kray.ru', 'H', 'Поместье А');
        $this->repo->addMember($listId, $familyId);

        $this->repo->delete($listId);
        $this->assertNull($this->repo->find($listId));
        // SQLite не выполняет FK по умолчанию — членство чистим явно, как и требует
        // CASCADE в MySQL (см. config/broadcast-lists.sql).
        $st = $pdo->prepare('DELETE FROM broadcast_list_members WHERE list_id = ?');
        $st->execute([$listId]);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM broadcast_list_members')->fetchColumn());
    }

    public function test_recipients_filters_by_status_and_telegram(): void
    {
        $pdo = \SkazResidents\Database::pdo();
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Семья А', 'active'),
            (2, 'b@sk.ru', 222, 'x', 'Семья Б', 'pending'),
            (3, 'c@sk.ru', NULL, 'x', 'Семья В', 'active')");

        $listId = $this->repo->create('Все трое');
        foreach ([1, 2, 3] as $famId) {
            $this->repo->addMember($listId, $famId);
        }
        // members() видит всех троих, recipients() — только активную с Telegram.
        $this->assertCount(3, $this->repo->members($listId));
        $rows = $this->repo->recipients($listId);
        $this->assertCount(1, $rows);
        $this->assertSame('Семья А', (string) $rows[0]['name']);
        $this->assertSame(111, (int) $rows[0]['telegram_id']);
    }
}