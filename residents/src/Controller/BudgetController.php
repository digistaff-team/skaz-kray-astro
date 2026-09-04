<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, View};
use SkazResidents\Service\LedgerReport;

/**
 * Отчёт «Бюджет Общего дома» для жителей — read-only. Виден любому
 * авторизованному жителю (Auth::requireLogin). Данные собирает LedgerReport —
 * тот же источник, что у страницы совета, поэтому цифры совпадают.
 */
final class BudgetController
{
    public function __construct(
        private LedgerReport $report = new LedgerReport()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $ym = isset($_GET['mesyac']) ? (string) $_GET['mesyac'] : null;
        View::render('budget/report', [
            'report'     => $this->report->build($ym),
            'editable'   => false,
            'basePath'   => '/poselenie/byudzhet',
            'uploadsUrl' => rtrim((string) Config::get('uploads_url'), '/'),
        ], 'Бюджет Общего дома');
    }
}
