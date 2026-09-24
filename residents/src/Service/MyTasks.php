<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Auth, Sections};
use SkazResidents\Repository\{
    ToolLoanRepository, BookLoanRepository, TripBookingRepository,
    ProductRepository, DiaryRepository, PurchaseRepository, FamilyRepository
};

/**
 * «Мои дела» — всё, что ждёт действия жителя, собранное из текущих статусов.
 * Спека: docs/superpowers/specs/2026-09-24-moi-dela-design.md.
 *
 * Отдельной таблицы нет: дело существует, пока существует и стоит в нужном
 * статусе сама заявка, объявление или закупка. Поэтому список не расходится с
 * реальностью и не зависит ни от почты, ни от бота — ради этого он и заведён:
 * у жителей из Telegram и MAX почты нет, а бот пишет только тем, кто разрешил.
 *
 * Дело — массив ['kind', 'title', 'detail', 'link', 'date', 'urgent'].
 * date — момент, с которого дело ждёт; по нему сортируем.
 * title и detail — обычный текст с именами и названиями из базы: при выводе экранировать.
 */
final class MyTasks
{
    /** Результат для вошедшего жителя на время запроса — см. forCurrent(). */
    private static ?array $current = null;
    /** Для кого посчитан $current: сменился житель в том же запросе — считаем заново. */
    private static ?int $currentFor = null;

    public function __construct(
        private ToolLoanRepository $toolLoans = new ToolLoanRepository(),
        private BookLoanRepository $bookLoans = new BookLoanRepository(),
        private TripBookingRepository $bookings = new TripBookingRepository(),
        private ProductRepository $products = new ProductRepository(),
        private DiaryRepository $diary = new DiaryRepository(),
        private PurchaseRepository $purchases = new PurchaseRepository(),
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /**
     * Дела вошедшего жителя — один расчёт на запрос: главная, блок, меню и
     * страница «Мои дела» берут один и тот же список. Любой сбой — пустой
     * список и запись в лог: число в меню есть на каждой странице, и из-за дел
     * не должна падать ни одна.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forCurrent(): array
    {
        $id = Auth::id();
        if ($id === null) { return []; }
        if (self::$current !== null && self::$currentFor === $id) { return self::$current; }
        try {
            self::$current = (new self())->build($id, Auth::isEditor(), date('Y-m-d'));
        } catch (\Throwable $e) {
            error_log('MyTasks: ' . self::describe($e));
            self::$current = [];
        }
        self::$currentFor = $id;
        return self::$current;
    }

    /** Забыть запомненный результат (тесты; в вебе запрос и так живёт один раз). */
    public static function reset(): void
    {
        self::$current = null;
        self::$currentFor = null;
    }

    /** @return array<int,array<string,mixed>> */
    public function build(int $familyId, bool $isEditor, string $today): array
    {
        $tasks = [
            ...$this->collect('instrumenty', fn(): array => $this->toolRequests($familyId)),
            ...$this->collect('knigi',       fn(): array => $this->bookRequests($familyId)),
            ...$this->collect('poezdki',     fn(): array => $this->tripBookings($familyId, $today)),
            ...$this->collect('yarmarka',    fn(): array => $this->rejectedProducts($familyId)),
            ...$this->collect('dnevniki',    fn(): array => $this->rejectedDiary($familyId)),
            ...$this->collect('instrumenty', fn(): array => $this->overdueTools($familyId, $today)),
            ...$this->collect('knigi',       fn(): array => $this->overdueBooks($familyId, $today)),
            ...$this->collect('zakupki',     fn(): array => $this->purchasesAsOrganizer($familyId, $today)),
            ...$this->collect('zakupki',     fn(): array => $this->purchasesAsParticipant($familyId)),
        ];
        if ($isEditor) {
            $tasks = [...$tasks, ...$this->collect(null, fn(): array => $this->moderationQueue())];
        }
        return self::sort($tasks);
    }

    /**
     * Один источник дел. Выключенный раздел не опрашивается вовсе; сбой
     * источника — в лог, остальные дела показываем.
     *
     * @param callable():array<int,array<string,mixed>> $source
     * @return array<int,array<string,mixed>>
     */
    private function collect(?string $section, callable $source): array
    {
        try {
            if ($section !== null && !Sections::isEnabled($section)) { return []; }
            return $source();
        } catch (\Throwable $e) {
            error_log('MyTasks (' . ($section ?? 'moderation') . '): ' . self::describe($e));
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function toolRequests(int $me): array
    {
        $out = [];
        foreach ($this->toolLoans->listIncoming($me, ['requested']) as $l) {
            $out[] = self::task('tool_request', 'Заявка на «' . $l['tool_name'] . '»',
                self::withDue((string) $l['borrower_name'], $l['due_date'] ?? null),
                '/poselenie/instrumenty/moi', (string) $l['requested_at']);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function bookRequests(int $me): array
    {
        $out = [];
        foreach ($this->bookLoans->listIncoming($me, ['requested']) as $l) {
            $out[] = self::task('book_request', 'Бронь книги «' . $l['book_title'] . '»',
                self::withDue((string) $l['borrower_name'], $l['due_date'] ?? null),
                '/poselenie/knigi/moi', (string) $l['requested_at']);
        }
        return $out;
    }

    /**
     * Брони в поездках жителя. Отмена поездки брони не гасит, поэтому
     * отсекаем по статусу самой поездки и по её дате.
     *
     * @return array<int,array<string,mixed>>
     */
    private function tripBookings(int $me, string $today): array
    {
        $out = [];
        foreach ($this->bookings->listIncoming($me, ['requested']) as $b) {
            if (($b['trip_status'] ?? '') !== 'active' || (string) $b['trip_date'] < $today) { continue; }
            $out[] = self::task('trip_booking', 'Бронь в поездке ' . $b['origin'] . ' → ' . $b['destination'],
                $b['passenger_name'] . ', мест: ' . (int) $b['seats'] . ' · ' . ru_date((string) $b['trip_date']),
                '/poselenie/poezdki/moi', (string) $b['created_at']);
        }
        return $out;
    }

    /**
     * Отклонённое модератором. Дата — момент отправки на проверку: reject()
     * updated_at не трогает. Для «дольше ждёт» это честно — ждёт с отправки.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rejectedProducts(int $me): array
    {
        $out = [];
        foreach ($this->products->listRejectedByFamily($me) as $p) {
            $out[] = self::task('product_rejected', 'Объявление «' . $p['title'] . '» не прошло проверку',
                self::reason($p['reject_reason'] ?? null),
                '/poselenie/yarmarka/' . (int) $p['id'] . '/redaktirovat', (string) $p['updated_at']);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function rejectedDiary(int $me): array
    {
        $out = [];
        foreach ($this->diary->listRejectedByFamily($me) as $e) {
            $out[] = self::task('diary_rejected', 'Запись «' . $e['title'] . '» не прошла проверку',
                self::reason($e['reject_reason'] ?? null),
                '/poselenie/dnevnik/' . (int) $e['id'] . '/redaktirovat', (string) $e['updated_at']);
        }
        return $out;
    }

    /**
     * Взятое у соседа, у которого прошёл желаемый срок, — срочное дело. Отбор
     * в запросе: «прошёл» — со следующего дня после срока.
     *
     * @return array<int,array<string,mixed>>
     */
    private function overdueTools(int $me, string $today): array
    {
        $out = [];
        foreach ($this->toolLoans->listOverdueForBorrower($me, $today) as $l) {
            $due = (string) $l['due_date'];
            $out[] = self::task('tool_overdue', 'Пора вернуть «' . $l['tool_name'] . '»',
                $l['owner_name'] . ' · срок был ' . ru_date($due),
                '/poselenie/instrumenty/moi', $due, true);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function overdueBooks(int $me, string $today): array
    {
        $out = [];
        foreach ($this->bookLoans->listOverdueForBorrower($me, $today) as $l) {
            $due = (string) $l['due_date'];
            $out[] = self::task('book_overdue', 'Пора вернуть книгу «' . $l['book_title'] . '»',
                $l['owner_name'] . ' · срок был ' . ru_date($due),
                '/poselenie/knigi/moi', $due, true);
        }
        return $out;
    }

    /**
     * Открытые закупки, которые ведёт житель: сбор закончился, а закупка всё ещё
     * «идёт сбор»; привезли, а у кого-то не отмечена оплата.
     *
     * Дата «привезли» — updated_at закупки: её двигает и смена стадии, и правка
     * организатора. На порядок в списке это влияет, на само дело — нет.
     *
     * @return array<int,array<string,mixed>>
     */
    private function purchasesAsOrganizer(int $me, string $today): array
    {
        $out = [];
        foreach ($this->purchases->listOpenByOrganizer($me) as $p) {
            $link = '/poselenie/zakupki/' . (int) $p['id'];
            $deadline = (string) ($p['deadline'] ?? '');
            if ($p['status'] === 'collecting' && $deadline !== '' && $deadline < $today) {
                $out[] = self::task('purchase_deadline', 'Сбор по закупке «' . $p['title'] . '» закончился',
                    'Оформите заказ или продлите срок · срок был ' . ru_date($deadline), $link, $deadline);
            }
            $unpaid = (int) ($p['unpaid_count'] ?? 0);
            if ($p['status'] === 'arrived' && $unpaid > 0) {
                $out[] = self::task('purchase_unpaid', 'Отметьте оплату: «' . $p['title'] . '»',
                    'Не отмечено у ' . $unpaid . ' ' . plural_ru($unpaid, 'участника', 'участников', 'участников'),
                    $link, (string) $p['updated_at']);
            }
        }
        return $out;
    }

    /**
     * Чужие закупки, где житель участник: привезли — забрать. Закрывается той же
     * отметкой оплаты, что и дело организатора: иначе забравший всё житель видел
     * бы дело, пока закупку не переведут в «завершена».
     * Опирается на то, что в поселении платят при выдаче (решено при
     * проектировании 2026-09-24): при предоплате отметку ставят раньше, и
     * «забрать» не появится — тогда закрывать дело надо по стадии «завершена».
     *
     * @return array<int,array<string,mixed>>
     */
    private function purchasesAsParticipant(int $me): array
    {
        $out = [];
        foreach ($this->purchases->listArrivedUnpaidForParticipant($me) as $p) {
            $pickup = trim((string) ($p['pickup'] ?? ''));
            $out[] = self::task('purchase_pickup', 'Привезли «' . $p['title'] . '» — заберите',
                $pickup !== '' ? 'Где забрать: ' . $pickup : 'Уточните у организатора: ' . $p['organizer_name'],
                '/poselenie/zakupki/' . (int) $p['id'], (string) $p['updated_at']);
        }
        return $out;
    }

    /**
     * Сводка очереди модерации — одной строкой, только редактору и админу.
     * В пояснении — только непустые части. Очередь маленькая, поэтому считаем
     * через существующие listPending(), без отдельных запросов-счётчиков.
     *
     * @return array<int,array<string,mixed>>
     */
    private function moderationQueue(): array
    {
        $parts = array_filter([
            'заявки на вход' => count($this->families->listByStatus('pending')),
            'записи'         => count($this->diary->listPending()),
            'объявления'     => count($this->products->listPending()),
        ]);
        $total = array_sum($parts);
        if ($total === 0) { return []; }
        $detail = implode(' · ', array_map(
            static fn(string $what, int $n): string => $what . ': ' . $n,
            array_keys($parts), $parts
        ));
        return [self::task('moderation', 'На проверке: ' . $total, $detail, '/poselenie/moderation', '')];
    }

    /**
     * Срочные — сверху; дальше кто дольше ждёт, тот выше; сводка модерации —
     * последней: это не одно дело, а счётчик очереди.
     *
     * @param array<int,array<string,mixed>> $tasks
     * @return array<int,array<string,mixed>>
     */
    private static function sort(array $tasks): array
    {
        $rank = static fn(array $t): int => $t['kind'] === 'moderation' ? 2 : ($t['urgent'] ? 0 : 1);
        usort($tasks, static fn(array $a, array $b): int => [$rank($a), $a['date']] <=> [$rank($b), $b['date']]);
        return $tasks;
    }

    /** @return array<string,mixed> */
    private static function task(string $kind, string $title, string $detail, string $link, string $date, bool $urgent = false): array
    {
        return ['kind' => $kind, 'title' => $title, 'detail' => $detail, 'link' => $link, 'date' => $date, 'urgent' => $urgent];
    }

    /** «Семья Руденко · до 1 октября 2026» — кто просит и к какому сроку. */
    private static function withDue(string $who, ?string $due): string
    {
        return ($due ?? '') !== '' ? $who . ' · до ' . ru_date($due) : $who;
    }

    /**
     * Причина отказа модератора; без неё — что делать дальше. «Без указания
     * причины» пишет сама модерация (ModerationController::rejectEntry/rejectProduct),
     * когда поле оставили пустым.
     */
    private static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        return ($reason !== '' && $reason !== 'Без указания причины')
            ? 'Причина: ' . $reason
            : 'Исправьте и отправьте снова';
    }

    /** «RuntimeException: сообщение (MyTasks.php:42)» — чтобы по логу было видно, где упало. */
    private static function describe(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}
