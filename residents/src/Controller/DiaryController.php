<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{DiaryRepository, ImageRepository};

final class DiaryController
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('diary/form', ['entry' => null, 'images' => [], 'errors' => []], 'Новая запись');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('diary/form', ['entry' => $data, 'images' => [], 'errors' => $errors], 'Новая запись');
            return;
        }
        $now = date('Y-m-d H:i:s');
        $id = $this->diary->create(Auth::id(), $data['title'], $data['body'], $now);
        $this->handleUploads('entry', $id);
        Flash::set('success', 'Запись отправлена на проверку редактору.');
        header('Location: /poselenie/');
    }

    public function showEdit(array $params): void
    {
        Auth::requireLogin();
        $entry = $this->ownedOr404((int) $params['id']);
        View::render('diary/form', [
            'entry' => $entry,
            'images' => $this->images->listFor('entry', (int) $entry['id']),
            'errors' => [],
        ], 'Редактирование записи');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $entry = $this->ownedOr404((int) $params['id']);

        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $entry['id'];
            View::render('diary/form', ['entry' => $data, 'images' => $this->images->listFor('entry', (int) $entry['id']), 'errors' => $errors], 'Редактирование записи');
            return;
        }
        $this->diary->update((int) $entry['id'], $data['title'], $data['body'], date('Y-m-d H:i:s'));
        $this->handleUploads('entry', (int) $entry['id']);
        Flash::set('success', 'Изменения отправлены на повторную проверку.');
        header('Location: /poselenie/');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $entry = $this->ownedOr404((int) $params['id']);
        $this->images->deleteFor('entry', (int) $entry['id']);
        $this->diary->delete((int) $entry['id']);
        Flash::set('success', 'Запись удалена.');
        header('Location: /poselenie/');
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Заголовок: 2–200 символов.'; }
        if (!Validator::required($body)) { $errors['body'] = 'Текст записи не может быть пустым.'; }
        return [['title' => $title, 'body' => $body], $errors];
    }

    private function handleUploads(string $ownerType, int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $files = $this->normalizeFiles($_FILES['photos']);
        $sort = count($this->images->listFor($ownerType, $ownerId));
        foreach ($files as $file) {
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) {
                $this->images->add($ownerType, $ownerId, $name, $sort++);
            } elseif ($err !== null) {
                Flash::set('error', $err);
            }
        }
    }

    /** Приводит массив $_FILES[multiple] к списку одиночных записей. */
    private function normalizeFiles(array $f): array
    {
        if (!is_array($f['name'])) { return [$f]; }
        $out = [];
        foreach ($f['name'] as $i => $_) {
            $out[] = [
                'name' => $f['name'][$i], 'type' => $f['type'][$i],
                'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ];
        }
        return $out;
    }

    private function ownedOr404(int $id): array
    {
        $entry = $this->diary->findById($id);
        if (!$entry || (int) $entry['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Запись не найдена');
            exit;
        }
        return $entry;
    }
}
