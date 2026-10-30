<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\VkMembersCacheRepository;

/**
 * Кеш подписчиков ВК-группы: пустой кеш, запись, перезапись той же строки
 * (id = 1), свежесть по TTL и привязка к group_id. SQLite in-memory.
 */
final class VkMembersCacheRepositoryTest extends TestCase
{
    private const GID = 63502598;

    private VkMembersCacheRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new VkMembersCacheRepository();
    }

    public function test_empty_cache(): void
    {
        $this->assertNull($this->repo->members(self::GID));
        $this->assertSame('', $this->repo->fetchedAt(self::GID));
        $this->assertFalse($this->repo->isFresh(self::GID, '2026-10-29 12:00:00', 86400));
    }

    public function test_save_then_read(): void
    {
        $members = [
            ['id' => 1384227, 'screen_name' => 'ivan'],
            ['id' => 2206409, 'screen_name' => 'мария'],
        ];
        $this->repo->save(self::GID, $members, '2026-10-29 12:00:00');

        $this->assertSame($members, $this->repo->members(self::GID));
        $this->assertSame('2026-10-29 12:00:00', $this->repo->fetchedAt(self::GID));
    }

    /** Повторное сохранение перезаписывает единственную строку, а не плодит новые. */
    public function test_second_save_overwrites_single_row(): void
    {
        $this->repo->save(self::GID, [['id' => 1]], '2026-10-29 12:00:00');
        $this->repo->save(self::GID, [['id' => 2]], '2026-10-29 13:00:00');

        $this->assertSame([['id' => 2]], $this->repo->members(self::GID));
        $rows = \SkazResidents\Database::pdo()
            ->query('SELECT COUNT(*) FROM vk_group_members_cache')->fetchColumn();
        $this->assertSame(1, (int) $rows);
    }

    /** TTL: в пределах суток кеш свежий, после — нет. */
    public function test_is_fresh_respects_ttl(): void
    {
        $this->repo->save(self::GID, [['id' => 1]], '2026-10-29 12:00:00');

        $this->assertTrue($this->repo->isFresh(self::GID, '2026-10-29 23:00:00', 86400));
        $this->assertFalse($this->repo->isFresh(self::GID, '2026-10-30 12:00:01', 86400));
    }

    /** Кеш привязан к group_id: для другой группы он не отдаётся. */
    public function test_cache_is_bound_to_group_id(): void
    {
        $this->repo->save(self::GID, [['id' => 1]], '2026-10-29 12:00:00');

        $this->assertNull($this->repo->members(123));
        $this->assertSame('', $this->repo->fetchedAt(123));
        $this->assertFalse($this->repo->isFresh(123, '2026-10-29 12:00:00', 86400));
    }

    public function test_clear_forgets_cache(): void
    {
        $this->repo->save(self::GID, [['id' => 1]], '2026-10-29 12:00:00');
        $this->repo->clear();

        $this->assertNull($this->repo->members(self::GID));
        $this->assertFalse($this->repo->isFresh(self::GID, '2026-10-29 12:00:00', 86400));
    }
}
