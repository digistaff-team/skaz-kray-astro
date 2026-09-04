<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\CouncilData;
use SkazResidents\Repository\{ToolRepository, BookRepository, TripRepository, DiaryRepository, CouncilTaskRepository};

/**
 * Собирает данные для мобильного лаунчера /poselenie/app: ближайшее собрание,
 * статус дневника семьи и счётчики разделов. Только чтение из существующих
 * репозиториев — единый источник, чтобы контроллер оставался тонким.
 * $today передаётся параметром (переносимо/тестируемо, без date() внутри).
 */
final class AppDashboard
{
    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository(),
        private ToolRepository $tools = new ToolRepository(),
        private BookRepository $books = new BookRepository(),
        private TripRepository $trips = new TripRepository(),
        private DiaryRepository $diary = new DiaryRepository()
    ) {}

    /** @return array<string,mixed> */
    public function build(int $familyId, ?string $councilName, string $today): array
    {
        $meeting = CouncilData::nextMeeting();

        // Дневник семьи: новее сверху (по id), последняя запись + её статус.
        $entries = $this->diary->listByFamily($familyId);
        usort($entries, static fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        $latest = $entries[0] ?? null;

        $active = $this->tasks->listWithSubtasks(false, 'priority');
        $councilMine = 0;
        if ($councilName !== null && $councilName !== '') {
            $councilMine = count(array_filter($active, static fn($t) => (string) $t['assignee'] === $councilName));
        }

        return [
            'meeting'     => $meeting,
            'agendaCount' => count($meeting['agenda'] ?? []),
            'diary'       => [
                'count'        => count($entries),
                'latestTitle'  => $latest['title'] ?? null,
                'latestStatus' => $latest['status'] ?? null,
            ],
            'counts' => [
                'toolsFree'     => count($this->tools->listCatalog('', '', 'available')),
                'books'         => count($this->books->listCatalog('', '', '')),
                'trips'         => count($this->trips->listUpcoming($today)),
                'councilActive' => count($active),
                'councilMine'   => $councilMine,
            ],
        ];
    }
}
