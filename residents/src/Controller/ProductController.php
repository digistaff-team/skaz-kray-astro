<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload, TelegramMedia};
use SkazResidents\Repository\{ProductRepository, ImageRepository};

final class ProductController
{
    public function __construct(
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    /** Лента «Товары соседей» — внутрипоселенческий рынок (все опубликованные товары). */
    public function index(): void
    {
        Auth::requireLogin();
        $products = $this->products->listAvailable(60, 0);
        foreach ($products as &$p) {
            $imgs = $this->images->listFor('product', (int) $p['id']);
            $p['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($p);
        View::render('product/marketplace', ['products' => $products, 'me' => Auth::id()], 'Товары соседей');
    }

    /** «Моя витрина» — свои товары/услуги (любой статус), управление. */
    public function mine(): void
    {
        Auth::requireLogin();
        $products = $this->products->listByFamily(Auth::id());
        foreach ($products as &$p) {
            $imgs = $this->images->listFor('product', (int) $p['id']);
            $p['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($p);
        View::render('product/mine', ['products' => $products], 'Моя витрина');
    }

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('product/form', ['product' => null, 'images' => [], 'errors' => []], 'Новый товар/услуга');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('product/form', ['product' => $data, 'images' => [], 'errors' => $errors], 'Новый товар/услуга');
            return;
        }
        $id = $this->products->create(Auth::id(), $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'), $data['visibility']);
        $this->handleUploads($id);
        Flash::set('success', $data['visibility'] === 'public'
            ? 'Товар отправлен на проверку — после неё появится в разделе Ярмарка на сайте.'
            : 'Товар опубликован на внутрипоселенческом рынке (виден соседям).');
        header('Location: /poselenie/yarmarka/moya');
    }

    public function showEdit(array $params): void
    {
        Auth::requireLogin();
        $product = $this->ownedOr404((int) $params['id']);
        View::render('product/form', [
            'product' => $product,
            'images' => $this->images->listFor('product', (int) $product['id']),
            'errors' => [],
        ], 'Редактирование товара');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $product['id'];
            View::render('product/form', ['product' => $data, 'images' => $this->images->listFor('product', (int) $product['id']), 'errors' => $errors], 'Редактирование товара');
            return;
        }
        $this->products->update((int) $product['id'], $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'), $data['visibility']);
        $this->handleUploads((int) $product['id']);
        Flash::set('success', $data['visibility'] === 'public'
            ? 'Изменения отправлены на проверку (раздел Ярмарка на сайте).'
            : 'Товар обновлён на внутрипоселенческом рынке.');
        header('Location: /poselenie/yarmarka/moya');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        $this->deleteImageFiles((int) $product['id']);
        $this->images->deleteFor('product', (int) $product['id']);
        $this->products->delete((int) $product['id']);
        Flash::set('success', 'Удалено.');
        header('Location: /poselenie/yarmarka/moya');
    }

    /** Удаление одного уже загруженного фото товара (в режиме редактирования). */
    public function deletePhoto(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        $imgId = (int) ($params['img'] ?? 0);
        foreach ($this->images->listFor('product', (int) $product['id']) as $img) {
            if ((int) $img['id'] !== $imgId) { continue; }
            $path = (string) $img['path'];
            if (!str_starts_with($path, 'tg:')) {
                @unlink(rtrim((string) Config::get('uploads_dir'), '/\\') . '/' . basename($path));
            }
            $this->images->deleteById($imgId);
            Flash::set('info', 'Фото удалено.');
            break;
        }
        header('Location: /poselenie/yarmarka/' . (int) $product['id'] . '/redaktirovat');
    }

    /** Удаляет физические файлы фото товара из uploads_dir (строки БД чистит deleteFor). */
    private function deleteImageFiles(int $ownerId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('product', $ownerId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Название: 2–200 символов.'; }
        if (!Validator::required($desc)) { $errors['description'] = 'Опишите товар или услугу.'; }
        if (!Validator::length($contact, 3, 200)) { $errors['contact'] = 'Укажите, как с вами связаться.'; }
        return [[
            'title' => $title, 'description' => $desc,
            'price' => $price === '' ? null : $price, 'contact' => $contact,
            'visibility' => $this->pickVisibility($_POST['visibility'] ?? ''),
        ], $errors];
    }

    /** residents («только соседи») | public («на сайте»); дефолт — residents. */
    private function pickVisibility(string $v): string
    {
        return $v === 'public' ? 'public' : 'residents';
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
        $sort = count($this->images->listFor('product', $ownerId));
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            // Фото товара уходят в тот же Telegram-канал, что дневник/новости.
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) { $this->images->add('product', $ownerId, 'tg:' . $fileId, $sort++); continue; }
            // Фолбэк на локальное хранилище, если Telegram недоступен.
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) { $this->images->add('product', $ownerId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    private function ownedOr404(int $id): array
    {
        $p = $this->products->findById($id);
        if (!$p || (int) $p['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Товар не найден');
            exit;
        }
        return $p;
    }
}
