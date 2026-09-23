<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\{DiaryRepository, PurchaseRepository};

/**
 * Данные мобильного лаунчера /poselenie/app: статус дневника семьи и счётчик
 * закупок. Только чтение.
 * Совет-специфики (собрание, задачи совета) в лаунчере больше нет — она живёт
 * в разделе совета (/sovet).
 *
 * Счётчиков у книг, инструментов и поездок здесь нет намеренно: на плитках
 * постоянные подписи, а не числа («0 поездок» на пустом разделе читался как
 * поломка). Каждый такой счётчик тянул из БД весь каталог раздела ради одного
 * count(), то есть три лишних выборки на каждое открытие главной.
 */
final class AppDashboard
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private PurchaseRepository $purchases = new PurchaseRepository()
    ) {}

    /**
     * Счётчик закупок. Главный экран не должен падать из-за одного раздела:
     * пока таблицы нет (схема накатывается вручную), показываем ноль.
     */
    private function collectingPurchases(): int
    {
        try {
            return $this->purchases->countCollecting();
        } catch (\Throwable $e) {
            error_log('AppDashboard: счётчик закупок недоступен — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Дата больше не нужна: её спрашивал только счётчик ближайших поездок.
     *
     * @return array<string,mixed>
     */
    public function build(int $familyId): array
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
                'purchases' => $this->collectingPurchases(),
            ],
        ];
    }
}
