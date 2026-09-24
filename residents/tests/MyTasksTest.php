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

    public function test_request_for_my_book_waits_until_decided(): void
    {
        $book = (new BookRepository())->create($this->me, 'Анастасия', 'В. Мегре', 'Проза', null, null, self::NOW);
        $loans = new BookLoanRepository();
        $loan = $loans->create($book, $this->neighbour, null, null, self::NOW);

        $t = $this->tasks();
        $this->assertSame(['book_request'], array_column($t, 'kind'));
        $this->assertSame('Бронь книги «Анастасия»', $t[0]['title']);
        $this->assertSame('Семья Руденко', $t[0]['detail']);
        $this->assertSame('/poselenie/knigi/moi', $t[0]['link']);

        $loans->decline($loan, self::NOW);
        $this->assertSame([], $this->kinds());
    }

    public function test_booking_on_my_trip_waits_until_decided(): void
    {
        $trip = (new TripRepository())->create($this->me, 'Терем', 'Краснодар', '2026-09-30', '09:00', 3, null, self::NOW);
        $bookings = new TripBookingRepository();
        $b = $bookings->create($trip, $this->neighbour, 2, null, self::NOW);

        $t = $this->tasks();
        $this->assertSame(['trip_booking'], array_column($t, 'kind'));
        $this->assertSame('Бронь в поездке Терем → Краснодар', $t[0]['title']);
        $this->assertSame('Семья Руденко, мест: 2 · 30 сентября 2026', $t[0]['detail']);
        $this->assertSame('/poselenie/poezdki/moi', $t[0]['link']);

        $bookings->setStatus($b, 'confirmed', self::NOW);
        $this->assertSame([], $this->kinds());
    }

    public function test_booking_on_past_or_cancelled_trip_is_not_a_task(): void
    {
        $trips = new TripRepository();
        $bookings = new TripBookingRepository();
        $past = $trips->create($this->me, 'А', 'Б', '2026-09-01', null, 3, null, self::NOW);
        $cancelled = $trips->create($this->me, 'В', 'Г', '2026-10-05', null, 3, null, self::NOW);
        $trips->setStatus($cancelled, 'cancelled');
        $bookings->create($past, $this->neighbour, 1, null, self::NOW);
        $bookings->create($cancelled, $this->neighbour, 1, null, self::NOW);
        // Контроль — поездка сегодня: она ещё не прошла, и бронь в ней должна быть делом.
        // Заодно видно, что источник работает и отсутствие остальных не случайно.
        $open = $trips->create($this->me, 'Д', 'Е', self::TODAY, null, 3, null, self::NOW);
        $bookings->create($open, $this->neighbour, 1, null, self::NOW);

        $this->assertSame(['Бронь в поездке Д → Е'], array_column($this->tasks(), 'title'));
    }

    public function test_rejected_listing_waits_until_resubmitted(): void
    {
        $products = new ProductRepository();
        $id = $products->create($this->me, 'Мёд', 'Майский', '500', '@me', self::NOW);
        $products->reject($id, 'Нет цены за единицу');

        // Чужое отклонённое объявление — не моё дело.
        $theirs = $products->create($this->neighbour, 'Яйца', 'Домашние', '120', '@n', self::NOW);
        $products->reject($theirs, 'Нет фото');

        $t = $this->tasks();
        $this->assertSame(['product_rejected'], array_column($t, 'kind'));
        $this->assertSame('Объявление «Мёд» не прошло проверку', $t[0]['title']);
        $this->assertSame('Причина: Нет цены за единицу', $t[0]['detail']);
        $this->assertSame('/poselenie/yarmarka/' . $id . '/redaktirovat', $t[0]['link']);

        // Правка возвращает объявление на проверку — дело снято.
        $products->update($id, 'Мёд', 'Майский', '500', '@me', self::NOW, 'public', 'кг.');
        $this->assertSame([], $this->kinds());
    }

    public function test_rejected_diary_entry_without_reason_says_what_to_do(): void
    {
        $diary = new DiaryRepository();
        $id = $diary->create($this->me, 'Как копали пруд', 'текст', 'public', self::NOW);
        $diary->reject($id, 'Без указания причины');   // так пишет модерация при пустой причине

        $t = $this->tasks();
        $this->assertSame(['diary_rejected'], array_column($t, 'kind'));
        $this->assertSame('Запись «Как копали пруд» не прошла проверку', $t[0]['title']);
        $this->assertSame('Исправьте и отправьте снова', $t[0]['detail']);
        $this->assertSame('/poselenie/dnevnik/' . $id . '/redaktirovat', $t[0]['link']);

        $diary->update($id, 'Как копали пруд', 'текст', 'public', self::NOW);
        $this->assertSame([], $this->kinds());
    }
}
