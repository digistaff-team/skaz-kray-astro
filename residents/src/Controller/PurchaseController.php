<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload, TelegramMedia};
use SkazResidents\Repository\{PurchaseRepository, PurchaseOrderRepository, ImageRepository};
use SkazResidents\Service\{CatalogAnnounce, PurchaseNotify};

/**
 * Совместные оптовые закупки (раздел жителей). Организатор-семья публикует
 * закупку одного товара с ценой за единицу и целью по объёму, соседи
 * записываются своим количеством (PurchaseOrderController).
 *
 * Стадии ведёт организатор: сбор → заказано → привезли → завершена, из любой
 * можно отменить. Автоматика ничего не закрывает по достижении цели — говорить
 * с поставщиком и решать всё равно человеку.
 */
final class PurchaseController
{
    use RequiresHousehold;

    /** Единицы измерения для подсказки в форме. */
    private const UNITS = ['кг', 'шт', 'л', 'мешок', 'упаковка', 'м', 'м²', 'м³'];

    public function __construct(
        private PurchaseRepository $purchases = new PurchaseRepository(),
        private PurchaseOrderRepository $orders = new PurchaseOrderRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function board(): void
    {
        $this->guard();
        $search   = trim($_GET['q'] ?? '');
        $category = trim($_GET['category'] ?? '');
        $status   = trim($_GET['status'] ?? '');
        $purchases = $this->purchases->listBoard($search, $category, $status);
        foreach ($purchases as &$p) {
            $imgs = $this->images->listFor('purchase', (int) $p['id']);
            $p['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($p);
        View::render('purchase/board', [
            'purchases'  => $purchases,
            'categories' => $this->purchases->categoriesForForm(),
            'q'          => $search,
            'category'   => $category,
            'status'     => $status,
        ], 'Закупки поселения');
    }

    public function show(array $params): void
    {
        $this->guard();
        $purchase = $this->purchases->findWithTotals((int) $params['id']);
        if (!$purchase) {
            http_response_code(404);
            View::render('public/notfound', [], 'Закупка не найдена');
            return;
        }
        $me = Auth::id();
        View::render('purchase/show', [
            'purchase'  => $purchase,
            'images'    => $this->images->listFor('purchase', (int) $purchase['id']),
            'orders'    => $this->orders->listFor((int) $purchase['id']),
            'myOrder'   => $this->orders->findFor((int) $purchase['id'], $me),
            'isOwner'   => (int) $purchase['organizer_id'] === $me,
            'errors'    => [],
        ], $purchase['title']);
    }

    public function showCreate(): void
    {
        $this->guard();
        View::render('purchase/form', [
            'purchase'   => null,
            'images'     => [],
            'categories' => $this->purchases->categoriesForForm(),
            'units'      => self::UNITS,
            'errors'     => [],
        ], 'Новая закупка');
    }

    public function create(): void
    {
        $this->guard();
        $this->csrfOrDie();
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('purchase/form', [
                'purchase' => $data, 'images' => [], 'categories' => $this->purchases->categoriesForForm(),
                'units' => self::UNITS, 'errors' => $errors,
            ], 'Новая закупка');
            return;
        }
        $id = $this->purchases->create(
            Auth::id(), $data['title'], $data['category'], $data['unit'], $data['price_per_unit'],
            $data['target_qty'], $data['deadline'], $data['supplier'], $data['pickup'], $data['note'],
            date('Y-m-d H:i:s')
        );
        $this->handleUploads($id);
        Flash::set('success', 'Закупка открыта — соседи могут записываться.');
        header('Location: /poselenie/zakupki/' . $id);
        CatalogAnnounce::purchase($id, $data['title'], $data['price_per_unit'], $data['unit']);   // уходит после ответа
    }

    public function showEdit(array $params): void
    {
        $this->guard();
        $purchase = $this->ownedOr404((int) $params['id']);
        View::render('purchase/form', [
            'purchase'   => $purchase,
            'images'     => $this->images->listFor('purchase', (int) $purchase['id']),
            'categories' => $this->purchases->categoriesForForm(),
            'units'      => self::UNITS,
            'errors'     => [],
        ], 'Редактирование закупки');
    }

    public function update(array $params): void
    {
        $this->guard();
        $this->csrfOrDie();
        $purchase = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $purchase['id'];
            $data['status'] = $purchase['status'];
            View::render('purchase/form', [
                'purchase' => $data, 'images' => $this->images->listFor('purchase', (int) $purchase['id']),
                'categories' => $this->purchases->categoriesForForm(), 'units' => self::UNITS, 'errors' => $errors,
            ], 'Редактирование закупки');
            return;
        }
        $this->purchases->update(
            (int) $purchase['id'], $data['title'], $data['category'], $data['unit'], $data['price_per_unit'],
            $data['target_qty'], $data['deadline'], $data['supplier'], $data['pickup'], $data['note'],
            date('Y-m-d H:i:s')
        );
        $this->handleUploads((int) $purchase['id']);
        Flash::set('success', 'Изменения сохранены.');
        header('Location: /poselenie/zakupki/' . $purchase['id']);
    }

    /**
     * Перевести закупку на следующую стадию (или отменить). Участников
     * уведомляем о двух переходах, которые требуют от них действий: заказ
     * отправлен поставщику и товар приехал.
     */
    public function setStatus(array $params): void
    {
        $this->guard();
        $this->csrfOrDie();
        $purchase = $this->ownedOr404((int) $params['id']);
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, PurchaseRepository::STATUSES, true) || $status === $purchase['status']) {
            header('Location: /poselenie/zakupki/' . $purchase['id']);
            return;
        }
        $this->purchases->setStatus((int) $purchase['id'], $status, date('Y-m-d H:i:s'));
        Flash::set('success', match ($status) {
            'collecting' => 'Сбор снова открыт.',
            'ordered'    => 'Закупка отмечена заказанной — участники получат уведомление.',
            'arrived'    => 'Отмечено: товар приехал. Участники получат уведомление.',
            'done'       => 'Закупка завершена.',
            default      => 'Закупка отменена.',
        });
        header('Location: /poselenie/zakupki/' . $purchase['id']);

        $ids = $this->orders->participantIds((int) $purchase['id']);
        if ($status === 'ordered')   { PurchaseNotify::ordered($ids, (string) $purchase['title']); }
        if ($status === 'arrived')   { PurchaseNotify::arrived($ids, (string) $purchase['title'], $purchase['pickup'] ?? null); }
        if ($status === 'cancelled') { PurchaseNotify::cancelled($ids, (string) $purchase['title']); }
    }

    public function delete(array $params): void
    {
        $this->guard();
        $this->csrfOrDie();
        $purchase = $this->ownedOr404((int) $params['id']);
        $this->images->deleteFor('purchase', (int) $purchase['id']);
        $this->purchases->delete((int) $purchase['id']);   // вместе с заявками участников
        Flash::set('info', 'Закупка удалена.');
        header('Location: /poselenie/zakupki/moi');
    }

    public function mine(): void
    {
        $this->guard();
        $me = Auth::id();
        View::render('purchase/mine', [
            'organized'    => $this->purchases->listByOrganizer($me),
            'participating' => $this->purchases->listByParticipant($me),
        ], 'Мои закупки');
    }

    // --- helpers ---

    private function guard(): void
    {
        $this->requireHousehold('zakupki');
    }

    private function csrfOrDie(): void
    {
        Csrf::guard();
    }

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $title    = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unit     = trim($_POST['unit'] ?? '');
        $price    = trim($_POST['price_per_unit'] ?? '');
        $target   = trim($_POST['target_qty'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $supplier = trim($_POST['supplier'] ?? '');
        $pickup   = trim($_POST['pickup'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        $errors = [];

        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Название: 2–200 символов.'; }
        if ($unit === '' || mb_strlen($unit) > 20) { $errors['unit'] = 'Укажите единицу измерения (до 20 символов).'; }
        if ($price !== '' && self::num($price) === null) { $errors['price_per_unit'] = 'Цена — число, например 450 или 450.50.'; }
        if ($target !== '' && self::num($target) === null) { $errors['target_qty'] = 'Цель — число в выбранных единицах.'; }
        if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) { $errors['deadline'] = 'Дата в формате ГГГГ-ММ-ДД.'; }

        return [[
            'title'          => $title,
            'category'       => $category !== '' ? mb_substr($category, 0, 80) : null,
            'unit'           => $unit !== '' ? mb_substr($unit, 0, 20) : 'шт',
            'price_per_unit' => $price !== '' ? self::num($price) : null,
            'target_qty'     => $target !== '' ? self::num($target) : null,
            'deadline'       => $deadline !== '' ? $deadline : null,
            'supplier'       => $supplier !== '' ? mb_substr($supplier, 0, 200) : null,
            'pickup'         => $pickup !== '' ? mb_substr($pickup, 0, 200) : null,
            'note'           => $note !== '' ? $note : null,
        ], $errors];
    }

    /** «450,50» → «450.50»; не число — null (в БД уходит DECIMAL строкой). */
    private static function num(string $raw): ?string
    {
        $v = str_replace([' ', ','], ['', '.'], $raw);
        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v) ? $v : null;
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
        $sort = count($this->images->listFor('purchase', $ownerId));
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            // Фото товара — в тот же Telegram-канал, что дневники и Ярмарка.
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) { $this->images->add('purchase', $ownerId, 'tg:' . $fileId, $sort++); continue; }
            [$name, $err] = Upload::saveImage($file, $dir);   // фолбэк, если Telegram недоступен
            if ($name !== null) { $this->images->add('purchase', $ownerId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    private function ownedOr404(int $id): array
    {
        $p = $this->purchases->findById($id);
        if (!$p || (int) $p['organizer_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Закупка не найдена');
            exit;
        }
        return $p;
    }
}
