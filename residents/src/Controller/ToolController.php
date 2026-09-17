<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{ToolRepository, ToolLoanRepository, ImageRepository};

/**
 * Сервис шеринга инструментов (раздел жителей). Каталог виден только вошедшим
 * жителям; у каждого инструмента владелец-семья. Без модерации — новый
 * инструмент сразу в каталоге.
 */
final class ToolController
{
    use RequiresHousehold;

    public function __construct(
        private ToolRepository $tools = new ToolRepository(),
        private ToolLoanRepository $loans = new ToolLoanRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function catalog(): void
    {
        $this->requireHousehold('Инструменты');
        $search   = trim($_GET['q'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $status   = trim($_GET['status'] ?? '');
        $tools = $this->tools->listCatalog($search, $category, $status);
        foreach ($tools as &$t) {
            $imgs = $this->images->listFor('tool', (int) $t['id']);
            $t['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($t);
        View::render('tool/catalog', [
            'tools'      => $tools,
            'categories' => $this->tools->categoriesForForm(),
            'q'          => $search,
            'category'   => $category,
            'status'     => $status,
        ], 'Инструменты поселения');
    }

    public function show(array $params): void
    {
        $this->requireHousehold('Инструменты');
        $tool = $this->tools->findWithOwner((int) $params['id']);
        if (!$tool) {
            http_response_code(404);
            View::render('public/notfound', [], 'Инструмент не найден');
            return;
        }
        $me = Auth::id();
        $active = $this->loans->activeForTool((int) $tool['id']);
        $isOwner = (int) $tool['family_id'] === $me;
        $canRequest = !$isOwner && $tool['status'] === 'available' && $active === null;
        View::render('tool/show', [
            'tool'       => $tool,
            'images'     => $this->images->listFor('tool', (int) $tool['id']),
            'active'     => $active,
            'isOwner'    => $isOwner,
            'canRequest' => $canRequest,
            'history'    => $isOwner ? $this->loans->historyForTool((int) $tool['id']) : [],
            'errors'     => [],
        ], $tool['name']);
    }

    public function showCreate(): void
    {
        $this->requireHousehold('Инструменты');
        View::render('tool/form', ['tool' => null, 'images' => [], 'categories' => $this->tools->categoriesForForm(), 'errors' => []], 'Новый инструмент');
    }

    public function create(): void
    {
        $this->requireHousehold('Инструменты');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('tool/form', ['tool' => $data, 'images' => [], 'categories' => $this->tools->categoriesForForm(), 'errors' => $errors], 'Новый инструмент');
            return;
        }
        $id = $this->tools->create(Auth::id(), $data['name'], $data['category'], $data['description'], $data['condition_note'], $data['terms'], date('Y-m-d H:i:s'));
        $this->handleUploads($id);
        Flash::set('success', 'Инструмент добавлен в каталог.');
        header('Location: /poselenie/instrumenty/' . $id);
    }

    public function showEdit(array $params): void
    {
        $this->requireHousehold('Инструменты');
        $tool = $this->ownedOr404((int) $params['id']);
        View::render('tool/form', [
            'tool'       => $tool,
            'images'     => $this->images->listFor('tool', (int) $tool['id']),
            'categories' => $this->tools->categoriesForForm(),
            'errors'     => [],
        ], 'Редактирование инструмента');
    }

    public function update(array $params): void
    {
        $this->requireHousehold('Инструменты');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $tool = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $tool['id'];
            $data['status'] = $tool['status']; // чтобы поле статуса показало реальное состояние
            View::render('tool/form', ['tool' => $data, 'images' => $this->images->listFor('tool', (int) $tool['id']), 'categories' => $this->tools->categoriesForForm(), 'errors' => $errors], 'Редактирование инструмента');
            return;
        }
        $this->tools->update((int) $tool['id'], $data['name'], $data['category'], $data['description'], $data['condition_note'], $data['terms'], date('Y-m-d H:i:s'));
        $this->handleUploads((int) $tool['id']);
        // Статус из формы редактирования: свободен / на руках / в ремонте. Скрытый
        // инструмент форма не трогает (скрытие снимается кнопкой в «Моих
        // инструментах»), и статус не меняем при активной заявке — иначе разошлись
        // бы с системой выдачи, где on_loan ставится самой заявкой.
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['available', 'on_loan', 'maintenance'], true)) { $status = 'available'; }
        if ($tool['status'] !== 'hidden' && $this->loans->activeForTool((int) $tool['id']) === null) {
            $this->tools->setStatus((int) $tool['id'], $status);
        }
        Flash::set('success', 'Изменения сохранены.');
        header('Location: /poselenie/instrumenty/' . $tool['id']);
    }

    public function delete(array $params): void
    {
        $this->requireHousehold('Инструменты');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $tool = $this->ownedOr404((int) $params['id']);
        $this->deleteImageFiles((int) $tool['id']);
        $this->images->deleteFor('tool', (int) $tool['id']);
        $this->tools->delete((int) $tool['id']); // tool_loans уходят каскадом FK
        Flash::set('info', 'Инструмент удалён из каталога.');
        header('Location: /poselenie/instrumenty/moi');
    }

    /** Скрыть/показать инструмент (только когда он не на руках). */
    public function toggleHidden(array $params): void
    {
        $this->requireHousehold('Инструменты');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $tool = $this->ownedOr404((int) $params['id']);
        if ($tool['status'] === 'on_loan') {
            Flash::set('error', 'Нельзя скрыть инструмент, пока он на руках.');
        } else {
            $this->tools->setStatus((int) $tool['id'], $tool['status'] === 'hidden' ? 'available' : 'hidden');
            Flash::set('success', $tool['status'] === 'hidden' ? 'Инструмент снова в каталоге.' : 'Инструмент скрыт из каталога.');
        }
        header('Location: /poselenie/instrumenty/moi');
    }

    /** Переключить обслуживание/готовность (только когда не на руках). */
    public function toggleMaintenance(array $params): void
    {
        $this->requireHousehold('Инструменты');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $tool = $this->ownedOr404((int) $params['id']);
        if ($tool['status'] === 'on_loan') {
            Flash::set('error', 'Инструмент сейчас на руках.');
        } else {
            $this->tools->setStatus((int) $tool['id'], $tool['status'] === 'maintenance' ? 'available' : 'maintenance');
            Flash::set('success', $tool['status'] === 'maintenance' ? 'Инструмент готов к выдаче.' : 'Инструмент помечён на обслуживании.');
        }
        header('Location: /poselenie/instrumenty/moi');
    }

    public function mine(): void
    {
        $this->requireHousehold('Инструменты');
        $me = Auth::id();
        $myTools = $this->tools->listByFamily($me);
        foreach ($myTools as &$t) {
            $active = $this->loans->activeForTool((int) $t['id']);
            $t['holder'] = $active['borrower_name'] ?? null;
        }
        unset($t);
        View::render('tool/mine', [
            'tools'      => $myTools,
            'incoming'   => $this->loans->listIncoming($me, ['requested', 'on_loan']),
            'borrowings' => $this->loans->listByBorrower($me),
        ], 'Мои инструменты');
    }

    // --- helpers ---

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $name  = trim($_POST['name'] ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $cond  = trim($_POST['condition_note'] ?? '');
        $errors = [];
        // Залог: выбор из списка, при «Денежный залог» — сумма в рублях. В tools.terms
        // уходит готовая строка (её же разбирает форма редактирования); вариант
        // «custom» — старый текст условий, введённый до появления списка.
        $termsKind = (string) ($_POST['terms_kind'] ?? '');
        $deposit   = trim((string) ($_POST['deposit_amount'] ?? ''));
        $terms = null;
        if ($termsKind === 'none') {
            $terms = 'Без залога';
        } elseif ($termsKind === 'deposit') {
            if ($deposit === '' || !ctype_digit($deposit) || (int) $deposit <= 0) {
                $errors['terms'] = 'Укажите сумму залога — целое число рублей больше нуля.';
            } else {
                $terms = 'Денежный залог: ' . (int) $deposit . ' ₽';
            }
        } elseif ($termsKind === 'custom') {
            $custom = trim((string) ($_POST['terms_custom'] ?? ''));
            $terms = $custom !== '' ? mb_substr($custom, 0, 200) : null;
        }
        if (!Validator::length($name, 2, 200)) { $errors['name'] = 'Название: 2–200 символов.'; }
        if ($cat !== '' && !Validator::length($cat, 1, 80)) { $errors['category'] = 'Категория до 80 символов.'; }
        return [[
            'name' => $name,
            'category' => mb_substr($cat, 0, 80),
            'description'    => $desc !== '' ? $desc : null,
            'condition_note' => $cond !== '' ? mb_substr($cond, 0, 200) : null,
            'terms'          => $terms,
            // Для перерисовки формы после ошибки — чтобы выбор залога не сбрасывался.
            'terms_kind'     => $termsKind,
            'deposit_amount' => $deposit,
        ], $errors];
    }

    private function handleUploads(int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $f = $_FILES['photos'];
        $files = is_array($f['name'])
            ? array_map(fn($i) => [
                'name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ], array_keys($f['name']))
            : [$f];
        $sort = count($this->images->listFor('tool', $ownerId));
        foreach ($files as $file) {
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) { $this->images->add('tool', $ownerId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    private function deleteImageFiles(int $ownerId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('tool', $ownerId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    private function ownedOr404(int $id): array
    {
        $t = $this->tools->findById($id);
        if (!$t || (int) $t['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Инструмент не найден');
            exit;
        }
        return $t;
    }
}
