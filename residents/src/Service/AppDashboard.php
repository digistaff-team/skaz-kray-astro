<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository};

/**
 * Данные мобильного лаунчера /poselenie/app: статус дневника семьи и счётчики
 * разделов жителей. Только чтение. $today — параметром (тестируемо).
 * Совет-специфики (собрание, задачи совета) в лаунчере больше нет — она живёт
 * в разделе совета (/sovet).
 */
final class AppDashboard
{
    public function __construct(
        private ToolRepository $tools = new ToolRepository(),
        private BookRepository $books = new BookRepository(),
        private TripRepository $trips = new TripRepository(),
        private DiaryRepository $diary = new DiaryRepository()
    ) {}

    /** @return array<string,mixed> */
    public function build(int $familyId, string $today): array
    {
        $entries = $this->diary->listByFamily($familyId);
        usort($entries, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        $latest = $entries[0] ?? null;

        return [
            'diary' => [
                'count'        => count($entries),
                'latestTitle'  => $latest['title'] ?? null,
                'latestStatus' => $latest['status'] ?? null,
            ],
            'counts' => [
                'toolsFree' => count($this->tools->listCatalog('', '', 'available')),
                'books'     => count($this->books->listCatalog('', '', '')),
                'trips'     => count($this->trips->listUpcoming($today)),
            ],
        ];
    }
}
