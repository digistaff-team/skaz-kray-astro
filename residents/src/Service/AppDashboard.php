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

        // Две свежие опубликованные записи из дневников ДРУГИХ поместий.
        $others = [];
        foreach ($this->diary->listPublished(6, 0) as $e) {
            if ((int) $e['family_id'] === $familyId) { continue; }
            $others[] = ['id' => (int) $e['id'], 'title' => (string) $e['title'], 'family' => (string) ($e['family_name'] ?? '')];
            if (count($others) >= 2) { break; }
        }

        return [
            'diary' => [
                'count'        => count($entries),
                'latestTitle'  => $latest['title'] ?? null,
                'latestStatus' => $latest['status'] ?? null,
            ],
            'otherDiaries' => $others,
            'counts' => [
                'toolsFree' => count($this->tools->listCatalog('', '', 'available')),
                'books'     => count($this->books->listCatalog('', '', '')),
                'trips'     => count($this->trips->listUpcoming($today)),
            ],
        ];
    }
}
