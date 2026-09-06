<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Sections;
use SkazResidents\Repository\SectionSettingsRepository;

final class SectionsTest extends TestCase
{
    protected function setUp(): void
    {
        make_test_db();
        Sections::reset();
    }

    public function test_all_enabled_by_default(): void
    {
        $this->assertTrue(Sections::isEnabled('knigi'));
        $this->assertTrue(Sections::isEnabled('sosedi'));
    }

    public function test_reflects_disabled_after_reset(): void
    {
        (new SectionSettingsRepository())->setEnabled('knigi', false, '2026-09-06 10:00:00');
        Sections::reset();
        $this->assertFalse(Sections::isEnabled('knigi'));
        $this->assertTrue(Sections::isEnabled('instrumenty'));
    }

    public function test_unknown_key_is_always_enabled(): void
    {
        // «Наше поместье» и любой ключ вне LIST выключить нельзя.
        $this->assertTrue(Sections::isEnabled('moye-pomestie'));
    }
}
