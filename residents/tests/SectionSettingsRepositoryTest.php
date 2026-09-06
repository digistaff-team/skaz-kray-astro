<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\SectionSettingsRepository;

final class SectionSettingsRepositoryTest extends TestCase
{
    private SectionSettingsRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new SectionSettingsRepository();
    }

    public function test_no_overrides_means_nothing_disabled(): void
    {
        $this->assertSame([], $this->repo->disabledKeys());
    }

    public function test_disable_then_enable(): void
    {
        $this->repo->setEnabled('knigi', false, '2026-09-06 10:00:00');
        $this->assertSame(['knigi'], $this->repo->disabledKeys());

        // Повторное выключение той же строки — без дубликата и без ошибки.
        $this->repo->setEnabled('knigi', false, '2026-09-06 10:01:00');
        $this->assertSame(['knigi'], $this->repo->disabledKeys());

        $this->repo->setEnabled('knigi', true, '2026-09-06 10:02:00');
        $this->assertSame([], $this->repo->disabledKeys());
    }

    public function test_multiple_disabled(): void
    {
        $this->repo->setEnabled('knigi', false, '2026-09-06 10:00:00');
        $this->repo->setEnabled('poezdki', false, '2026-09-06 10:00:00');
        $d = $this->repo->disabledKeys();
        sort($d);
        $this->assertSame(['knigi', 'poezdki'], $d);
    }
}
