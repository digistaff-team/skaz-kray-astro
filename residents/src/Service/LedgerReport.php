<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\ImageRepository;

/**
 * Собирает модель наглядного отчёта «Бюджет Общего дома» из репозиториев —
 * единый источник правды для страницы совета и страницы жителей (цифры
 * идентичны). Ничего не пишет, только читает.
 *
 * build(?$selectedYm) → [
 *   'months'       => [ ['ym','label','income','expense','balance'], ... ] (новые сверху),
 *   'totalIncome','totalExpense','totalBalance' => float (за всё время),
 *   'monthIncome','monthExpense','monthBalance' => float (выбранный месяц),
 *   'selectedYm'   => 'YYYY-MM'|null, 'selectedLabel' => string,
 *   'breakdown'    => [ ['name','sum','pct'], ... ] (расходы месяца, по убыванию),
 *   'operations'   => [ ['id','kind','category','amount','entry_date','note','hasReceipt','receiptPath'], ... ],
 * ]
 */
final class LedgerReport
{
    private const MONTHS_RU = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    public function __construct(
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function build(?string $selectedYm = null): array
    {
        $sums       = $this->ledger->sumsByMonth();
        $monthsList = $this->ledger->monthsWithData(); // новые сверху

        $months = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        foreach ($monthsList as $ym) {
            $income  = $sums[$ym]['income']  ?? 0.0;
            $expense = $sums[$ym]['expense'] ?? 0.0;
            $months[] = [
                'ym'      => $ym,
                'label'   => $this->label($ym),
                'income'  => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
            ];
            $totalIncome  += $income;
            $totalExpense += $expense;
        }

        // Выбранный месяц: переданный (если есть данные) либо последний.
        if ($selectedYm === null || !in_array($selectedYm, $monthsList, true)) {
            $selectedYm = $monthsList[0] ?? null;
        }

        $breakdown = [];
        $operations = [];
        if ($selectedYm !== null) {
            $rows = $this->ledger->expenseByCategory($selectedYm);
            $expTotal = array_sum(array_map(static fn($r) => $r['sum'], $rows));
            foreach ($rows as $r) {
                $breakdown[] = [
                    'name' => $r['name'],
                    'sum'  => $r['sum'],
                    'pct'  => $expTotal > 0 ? (int) round($r['sum'] / $expTotal * 100) : 0,
                ];
            }
            foreach ($this->ledger->listForMonth($selectedYm) as $op) {
                $receipt = $op['kind'] === 'expense'
                    ? $this->images->listFor('expense', (int) $op['id'])
                    : [];
                $operations[] = [
                    'id'          => (int) $op['id'],
                    'kind'        => $op['kind'],
                    'category'    => (string) $op['category_name'],
                    'amount'      => (float) $op['amount'],
                    'entry_date'  => (string) $op['entry_date'],
                    'note'        => (string) $op['note'],
                    'hasReceipt'  => count($receipt) > 0,
                    'receiptPath' => $receipt[0]['path'] ?? null,
                ];
            }
        }

        return [
            'months'        => $months,
            'totalIncome'   => $totalIncome,
            'totalExpense'  => $totalExpense,
            'totalBalance'  => $totalIncome - $totalExpense,
            'monthIncome'   => $selectedYm !== null ? ($sums[$selectedYm]['income']  ?? 0.0) : 0.0,
            'monthExpense'  => $selectedYm !== null ? ($sums[$selectedYm]['expense'] ?? 0.0) : 0.0,
            'monthBalance'  => $selectedYm !== null ? (($sums[$selectedYm]['income'] ?? 0.0) - ($sums[$selectedYm]['expense'] ?? 0.0)) : 0.0,
            'selectedYm'    => $selectedYm,
            'selectedLabel' => $selectedYm !== null ? $this->label($selectedYm) : '',
            'breakdown'     => $breakdown,
            'operations'    => $operations,
        ];
    }

    private function label(string $ym): string
    {
        [$y, $m] = array_pad(explode('-', $ym), 2, '0');
        $name = self::MONTHS_RU[(int) $m] ?? $ym;
        return $name . ' ' . $y;
    }
}
