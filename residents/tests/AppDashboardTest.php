<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Database;
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\DiaryRepository;

final class AppDashboardTest extends TestCase
{
    protected function setUp(): void { make_test_db(); }

    private function makeFamily(int $id): void
    {
        $st = Database::pdo()->prepare(
            "INSERT INTO families (id, email, password_hash, name, status, role)
             VALUES (?, ?, 'x', 'Семья Шубиных', 'active', 'resident')"
        );
        $st->execute([$id, 'family' . $id . '@example.com']);
    }

    private function seed(): int
    {
        $familyId = 7;
        $this->makeFamily($familyId);
        $d = new DiaryRepository();
        $d->create($familyId, 'Старая запись', 'тело', 'residents', '2026-08-01 10:00:00');
        $d->create($familyId, 'Как мы копали пруд', 'тело', 'residents', '2026-09-02 10:00:00');
        return $familyId;
    }

    public function test_diary_status(): void
    {
        $familyId = $this->seed();
        $r = (new AppDashboard())->build($familyId);

        $this->assertSame(2, $r['diary']['count']);
        $this->assertSame('Как мы копали пруд', $r['diary']['latestTitle']);
        $this->assertSame('published', $r['diary']['latestStatus']); // residents публикуется сразу

        // Совет-специфики в модели больше нет.
        $this->assertArrayNotHasKey('meeting', $r);
        $this->assertArrayNotHasKey('councilActive', $r['counts']);
    }

    /**
     * Счётчики книг, инструментов и поездок убраны вместе с числами на плитках:
     * каждый тянул из БД весь каталог раздела ради одного count(). Проверяем,
     * что они не вернулись незаметно — иначе главная снова начнёт их считать.
     */
    public function test_only_purchases_are_counted(): void
    {
        $r = (new AppDashboard())->build($this->seed());
        $this->assertSame(['purchases'], array_keys($r['counts']));
    }

    public function test_empty_family_does_not_crash(): void
    {
        $r = (new AppDashboard())->build(999);
        $this->assertSame(0, $r['diary']['count']);
        $this->assertNull($r['diary']['latestTitle']);
        $this->assertSame(0, $r['counts']['purchases']);
    }
}
