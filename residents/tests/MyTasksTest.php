<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Database, Sections};
use SkazResidents\Service\MyTasks;
use SkazResidents\Repository\{
    FamilyRepository, ToolRepository, ToolLoanRepository, BookRepository, BookLoanRepository,
    TripRepository, TripBookingRepository, ProductRepository, DiaryRepository,
    PurchaseRepository, PurchaseOrderRepository, SectionSettingsRepository
};

/**
 * «Мои дела»: каждое дело появляется при своём условии и исчезает после
 * действия — это и есть весь контракт списка, собранного из текущих статусов.
 */
final class MyTasksTest extends TestCase
{
    private const TODAY = '2026-09-24';
    private const NOW = '2026-09-20 10:00:00';
    private int $me;
    private int $neighbour;

    protected function setUp(): void
    {
        $_SESSION = [];
        make_test_db();
        Sections::reset();
        MyTasks::reset();
        $fam = new FamilyRepository();
        $this->me = $fam->createPending('me@skaz-kray.ru', 'H', 'Семья Шубиных');
        $this->neighbour = $fam->createPending('nei@skaz-kray.ru', 'H', 'Семья Руденко');
    }

    /** @return array<int,array<string,mixed>> */
    private function tasks(bool $editor = false, string $today = self::TODAY, ?int $who = null): array
    {
        return (new MyTasks())->build($who ?? $this->me, $editor, $today);
    }

    /** @return array<int,string> */
    private function kinds(bool $editor = false, string $today = self::TODAY, ?int $who = null): array
    {
        return array_column($this->tasks($editor, $today, $who), 'kind');
    }

    public function test_request_for_my_tool_waits_until_decided(): void
    {
        $tool = (new ToolRepository())->create($this->me, 'Дрель', 'Электро', null, null, null, self::NOW);
        $loans = new ToolLoanRepository();
        $loan = $loans->create($tool, $this->neighbour, null, '2026-10-01', self::NOW);

        $t = $this->tasks();
        $this->assertSame(['tool_request'], array_column($t, 'kind'));
        $this->assertSame('Заявка на «Дрель»', $t[0]['title']);
        $this->assertSame('Семья Руденко · до 1 октября 2026', $t[0]['detail']);
        $this->assertSame('/poselenie/instrumenty/moi', $t[0]['link']);
        $this->assertFalse($t[0]['urgent']);

        $loans->give($loan, self::NOW);
        $this->assertSame([], $this->kinds());
    }

    public function test_nothing_to_do_is_an_empty_list(): void
    {
        $this->assertSame([], $this->tasks());
    }

    public function test_disabled_section_is_silent(): void
    {
        $tool = (new ToolRepository())->create($this->me, 'Дрель', 'Электро', null, null, null, self::NOW);
        (new ToolLoanRepository())->create($tool, $this->neighbour, null, null, self::NOW);
        // Источник работает — иначе проверка ниже прошла бы впустую: сбои источников глотаются.
        $this->assertSame(['tool_request'], $this->kinds());

        (new SectionSettingsRepository())->setEnabled('instrumenty', false, self::NOW);
        Sections::reset();
        $this->assertSame([], $this->kinds());
    }

    public function test_for_current_is_empty_for_guest_and_computed_once_per_request(): void
    {
        $this->assertSame([], MyTasks::forCurrent());

        $_SESSION['family_id'] = $this->me;
        $tools = new ToolRepository();
        $loans = new ToolLoanRepository();
        $loans->create($tools->create($this->me, 'Дрель', 'x', null, null, null, self::NOW), $this->neighbour, null, null, self::NOW);
        $this->assertCount(1, MyTasks::forCurrent());

        // Запомнено на запрос: главная, блок и меню берут один и тот же список.
        $loans->create($tools->create($this->me, 'Пила', 'x', null, null, null, self::NOW), $this->neighbour, null, null, self::NOW);
        $this->assertCount(1, MyTasks::forCurrent());
        MyTasks::reset();
        $this->assertCount(2, MyTasks::forCurrent());
    }

    public function test_for_current_recomputes_when_resident_changes(): void
    {
        $_SESSION['family_id'] = $this->me;
        $tool = (new ToolRepository())->create($this->me, 'Дрель', 'x', null, null, null, self::NOW);
        (new ToolLoanRepository())->create($tool, $this->neighbour, null, null, self::NOW);
        $this->assertCount(1, MyTasks::forCurrent());

        // Другой житель в том же процессе не должен увидеть чужие дела.
        $_SESSION['family_id'] = $this->neighbour;
        $this->assertSame([], MyTasks::forCurrent());
    }
}
