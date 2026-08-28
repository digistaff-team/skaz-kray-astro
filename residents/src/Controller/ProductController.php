<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{ProductRepository, ImageRepository};

final class ProductController
{
    public function __construct(
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

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
        $id = $this->products->create(Auth::id(), $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'));
        $this->handleUploads($id);
        Flash::set('success', 'Отправлено на проверку редактору.');
        header('Location: /poselenie/');
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
        $this->products->update((int) $product['id'], $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'));
        $this->handleUploads((int) $product['id']);
        Flash::set('success', 'Изменения отправлены на повторную проверку.');
        header('Location: /poselenie/');
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
        header('Location: /poselenie/');
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
        $sort = count($this->images->listFor('product', $ownerId));
        foreach ($files as $file) {
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
