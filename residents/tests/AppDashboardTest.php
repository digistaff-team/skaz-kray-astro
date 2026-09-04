<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Database;
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository};

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
        $t = new ToolRepository();
        $t->create($familyId, 'Дрель', 'Электро', null, null, null, '2026-09-01 10:00:00');
        $t->create($familyId, 'Лопата', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $busy = $t->create($familyId, 'Пила', 'Сад', null, null, null, '2026-09-01 10:00:00');
        $t->setStatus($busy, 'on_loan');
        $b = new BookRepository();
        $b->create($familyId, 'Книга А', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга Б', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $b->create($familyId, 'Книга В', 'Автор', 'Жанр', null, null, '2026-09-01 10:00:00');
        $tr = new TripRepository();
        $tr->create($familyId, 'Терем', 'Северская', '2026-09-10', '09:00', 3, null, '2026-09-01 10:00:00');
        $tr->create($familyId, 'Терем', 'Краснодар', '2026-08-01', '09:00', 3, null, '2026-08-01 10:00:00');
        return $familyId;
    }

    public function test_counts_and_diary_status(): void
    {
        $familyId = $this->seed();
        $r = (new AppDashboard())->build($familyId, '2026-09-04');

        $this->assertSame(2, $r['counts']['toolsFree']);
        $this->assertSame(3, $r['counts']['books']);
        $this->assertSame(1, $r['counts']['trips']);

        $this->assertSame(2, $r['diary']['count']);
        $this->assertSame('Как мы копали пруд', $r['diary']['latestTitle']);
        $this->assertSame('pending', $r['diary']['latestStatus']);

        // Совет-специфики в модели больше нет.
        $this->assertArrayNotHasKey('meeting', $r);
        $this->assertArrayNotHasKey('councilActive', $r['counts']);
    }

    public function test_empty_family_does_not_crash(): void
    {
        $r = (new AppDashboard())->build(999, '2026-09-04');
        $this->assertSame(0, $r['diary']['count']);
        $this->assertNull($r['diary']['latestTitle']);
        $this->assertSame(0, $r['counts']['toolsFree']);
    }
}
