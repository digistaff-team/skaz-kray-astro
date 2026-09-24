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
 */
final class MyTasks
{
    /** Результат для вошедшего жителя на время запроса — см. forCurrent(). */
    private static ?array $current = null;

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
        if (self::$current !== null) { return self::$current; }
        $id = Auth::id();
        if ($id === null) { return []; }
        try {
            self::$current = (new self())->build($id, Auth::isEditor(), date('Y-m-d'));
        } catch (\Throwable $e) {
            error_log('MyTasks: ' . $e->getMessage());
            self::$current = [];
        }
        return self::$current;
    }

    /** Забыть запомненный результат (тесты; в вебе запрос и так живёт один раз). */
    public static function reset(): void
    {
        self::$current = null;
    }

    /** @return array<int,array<string,mixed>> */
    public function build(int $familyId, bool $isEditor, string $today): array
    {
        $tasks = [
            ...$this->collect('instrumenty', fn(): array => $this->toolRequests($familyId)),
        ];
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
        if ($section !== null && !Sections::isEnabled($section)) { return []; }
        try {
            return $source();
        } catch (\Throwable $e) {
            error_log('MyTasks (' . ($section ?? 'moderation') . '): ' . $e->getMessage());
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
}
