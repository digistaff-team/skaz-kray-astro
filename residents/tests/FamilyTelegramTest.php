<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\FamilyRepository;

final class FamilyTelegramTest extends TestCase
{
    private FamilyRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new FamilyRepository();
    }

    public function test_create_telegram_family_is_active_resident(): void
    {
        $id = $this->repo->createTelegramFamily(555000, 'Иван Петров');
        $f = $this->repo->findByTelegramId(555000);
        $this->assertSame($id, (int) $f['id']);
        $this->assertSame('active', $f['status']);
        $this->assertSame('resident', $f['role']);
        $this->assertSame('Иван Петров', $f['name']);
        $this->assertSame('tg555000@telegram.local', $f['email']);
        $this->assertSame(555000, (int) $f['telegram_id']);
    }

    public function test_find_by_telegram_id_null_when_absent(): void
    {
        $this->assertNull($this->repo->findByTelegramId(999));
    }

    public function test_telegram_and_email_accounts_coexist(): void
    {
        $this->repo->createPending('semya@skaz-kray.ru', 'H', 'Поместье Ивановых');
        $tgId = $this->repo->createTelegramFamily(777, 'ТГ Житель');
        $this->assertNotNull($this->repo->findByEmail('semya@skaz-kray.ru'));
        $this->assertNotNull($this->repo->findByTelegramId(777));
        $this->assertNull($this->repo->findByEmail('semya@skaz-kray.ru')['telegram_id']);
    }
}
