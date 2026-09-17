<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\FamilyRepository;

final class FamilyMaxTest extends TestCase
{
    private FamilyRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new FamilyRepository();
    }

    public function test_create_max_family_is_active_resident(): void
    {
        $id = $this->repo->createMaxFamily(643900, 'Иван Петров');
        $f = $this->repo->findByMaxId(643900);
        $this->assertSame($id, (int) $f['id']);
        $this->assertSame('active', $f['status']);
        $this->assertSame('resident', $f['role']);
        $this->assertSame('max643900@max.local', $f['email']);
        $this->assertSame(643900, (int) $f['max_user_id']);
    }

    public function test_find_by_max_id_null_when_absent(): void
    {
        $this->assertNull($this->repo->findByMaxId(999));
    }

    /** Слияние: MAX-привязка переезжает на существующий аккаунт, одноразовый удаляется. */
    public function test_merge_max_into_moves_binding_and_deletes_throwaway(): void
    {
        // Существующий аккаунт поместья (вход через Telegram), без MAX.
        $targetId = $this->repo->createTelegramFamily(555000, 'Семья Петровых');
        // Одноразовый аккаунт, созданный при первом входе через MAX.
        $throwId  = $this->repo->createMaxFamily(643900, 'Иван из MAX');

        $this->repo->mergeMaxInto($throwId, $targetId, 643900);

        // Одноразовый аккаунт удалён.
        $this->assertNull($this->repo->findById($throwId));
        // MAX теперь ведёт в целевой аккаунт.
        $target = $this->repo->findByMaxId(643900);
        $this->assertNotNull($target);
        $this->assertSame($targetId, (int) $target['id']);
        // Telegram-вход в тот же аккаунт продолжает работать.
        $this->assertSame($targetId, (int) $this->repo->findByTelegramId(555000)['id']);
    }
}
