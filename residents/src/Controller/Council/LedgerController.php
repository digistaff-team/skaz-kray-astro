<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Csrf, Flash, Config, Upload, View};
use SkazResidents\Service\LedgerReport;
use SkazResidents\Repository\{CouncilLedgerRepository, CouncilCategoryRepository, ImageRepository};

/**
 * Бухгалтерия совета — ввод и правка операций бюджета. Доступ: все члены совета
 * (CouncilAuth::requireLogin). Управление справочником статей — только админ
 * (методы categories/*, гард requireAdmin). Отчёт рендерит тем же партиалом,
 * что и страница жителей, но с $editable=true.
 */
final class LedgerController
{
    private const KINDS = ['income', 'expense'];

    public function __construct(
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private CouncilCategoryRepository $cats = new CouncilCategoryRepository(),
        private ImageRepository $images = new ImageRepository(),
        private LedgerReport $report = new LedgerReport()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireLogin();
        $ym = isset($_GET['mesyac']) ? (string) $_GET['mesyac'] : null;
        View::render('council/ledger', [
            'report'      => $this->report->build($ym),
            'editable'    => true,
            'basePath'    => '/sovet/buhgalteriya',
            'uploadsUrl'  => rtrim((string) Config::get('uploads_url'), '/'),
            'incomeCats'  => $this->cats->listByKind('income', true),
            'expenseCats' => $this->cats->listByKind('expense', true),
            'me'          => CouncilAuth::name(),
        ], 'Бухгалтерия', 'council/layout');
    }

    public function create(): void
    {
        $this->guard();
        $kind = in_array($_POST['kind'] ?? '', self::KINDS, true) ? $_POST['kind'] : '';
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $amount = max(0.0, (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '')));
        $date = trim($_POST['entry_date'] ?? '');
        $note = trim($_POST['note'] ?? '');

        $cat = $this->cats->find($categoryId);
        if ($kind === '' || !$cat || $cat['kind'] !== $kind) {
            Flash::set('error', 'Выберите статью, соответствующую типу операции.');
            $this->back(); return;
        }
        if ($amount <= 0) { Flash::set('error', 'Укажите сумму больше нуля.'); $this->back(); return; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { Flash::set('error', 'Укажите корректную дату.'); $this->back($date); return; }

        $id = $this->ledger->create($kind, $categoryId, $amount, $date, $note, CouncilAuth::name());
        if ($kind === 'expense') { $this->handleReceipt($id); }
        Flash::set('success', 'Операция добавлена.');
        $this->back($date);
    }

    public function update(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $entry = $this->ledger->find($id);
        if (!$entry) { $this->back(); return; }

        $patch = [];
        if (isset($_POST['category_id'])) {
            $cat = $this->cats->find((int) $_POST['category_id']);
            if ($cat && $cat['kind'] === $entry['kind']) { $patch['category_id'] = (int) $_POST['category_id']; }
        }
        if (isset($_POST['amount']))     { $patch['amount'] = max(0.0, (float) str_replace(',', '.', (string) $_POST['amount'])); }
        if (isset($_POST['note']))       { $patch['note']   = trim($_POST['note']); }
        if (isset($_POST['entry_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['entry_date']))) {
            $patch['entry_date'] = trim($_POST['entry_date']);
        }
        $this->ledger->updateFields($id, $patch);
        Flash::set('success', 'Операция обновлена.');
        $this->back();
    }

    public function delete(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if ($this->ledger->find($id)) {
            $this->deleteReceiptFiles($id);
            $this->images->deleteFor('expense', $id);
            $this->ledger->delete($id);
            Flash::set('info', 'Операция удалена.');
        }
        $this->back();
    }

    // ---- Управление справочником статей (только админ) ----

    public function categories(): void
    {
        CouncilAuth::requireAdmin();
        View::render('council/categories', [
            'income'  => $this->cats->listByKind('income', false),
            'expense' => $this->cats->listByKind('expense', false),
        ], 'Статьи бюджета', 'council/layout');
    }

    public function addCategory(): void
    {
        $this->guardAdmin();
        $kind = in_array($_POST['kind'] ?? '', self::KINDS, true) ? $_POST['kind'] : 'expense';
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') { $this->cats->create($kind, $name); Flash::set('success', 'Статья добавлена.'); }
        else { Flash::set('error', 'Название статьи не может быть пустым.'); }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    public function renameCategory(array $params = []): void
    {
        $this->guardAdmin();
        $id = (int) ($params['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && $this->cats->find($id)) { $this->cats->rename($id, $name); Flash::set('success', 'Статья переименована.'); }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    public function toggleCategory(array $params = []): void
    {
        $this->guardAdmin();
        $id = (int) ($params['id'] ?? 0);
        $cat = $this->cats->find($id);
        if ($cat) {
            $active = (int) $cat['is_active'] === 1;
            $this->cats->setActive($id, !$active);
            Flash::set('info', $active ? 'Статья убрана из выбора (архив).' : 'Статья снова доступна.');
        }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    // ---- helpers ----

    private function handleReceipt(int $entryId): void
    {
        if (empty($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return; }
        $dir = (string) Config::get('uploads_dir');
        [$name, $err] = Upload::saveImage($_FILES['receipt'], $dir);
        if ($name !== null) { $this->images->add('expense', $entryId, $name, 0); }
        elseif ($err !== null) { Flash::set('error', $err); }
    }

    private function deleteReceiptFiles(int $entryId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('expense', $entryId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    private function guard(): void
    {
        CouncilAuth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function guardAdmin(): void
    {
        CouncilAuth::requireAdmin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function back(string $ym = ''): void
    {
        if ($ym === '' && isset($_POST['mesyac'])) { $ym = (string) $_POST['mesyac']; }
        $ym = substr($ym, 0, 7);
        $q = preg_match('/^\d{4}-\d{2}$/', $ym) ? ('?mesyac=' . $ym) : '';
        header('Location: /sovet/buhgalteriya' . $q);
    }
}
