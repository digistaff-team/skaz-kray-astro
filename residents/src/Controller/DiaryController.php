<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload, TelegramMedia};
use SkazResidents\Repository\{DiaryRepository, ImageRepository};

final class DiaryController
{
    private const PER_PAGE = 10;

    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    /**
     * Внутренняя лента дневников — только для вошедших жителей. Показывает
     * ВСЕ опубликованные записи (независимо от галочки is_public), в отличие
     * от внешней публичной ленты (PublicController::diaryList), которая
     * фильтрует только записи с is_public=1.
     */
    public function feed(): void
    {
        Auth::requireLogin();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $entries = $this->diary->listPublished(self::PER_PAGE, $offset);
        foreach ($entries as &$e) {
            $e['images'] = $this->images->listFor('entry', (int) $e['id']);
        }
        unset($e);
        View::render('diary/feed_list', [
            'entries' => $entries,
            'page'    => $page,
            'total'   => $this->diary->countPublished(),
            'perPage' => self::PER_PAGE,
        ], 'Дневники поместий');
    }

    public function feedShow(array $params): void
    {
        Auth::requireLogin();
        $entry = $this->diary->findPublishedById((int) $params['id']);
        if (!$entry) { http_response_code(404); View::render('public/notfound', [], 'Запись не найдена'); return; }
        $entry['images'] = $this->images->listFor('entry', (int) $entry['id']);
        View::render('diary/feed_show', ['entry' => $entry], $entry['title']);
    }

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
        $id = $this->diary->create(Auth::id(), $data['title'], $data['body'], $data['visibility'], $now);
        $this->handleUploads('entry', $id);
        Flash::set('success', match ($data['visibility']) {
            'private'   => 'Личная запись сохранена — видна только вам.',
            'residents' => 'Запись опубликована в ленте дневников поселения.',
            default     => 'Запись отправлена на проверку редактору сайта.',
        });
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
        $this->diary->update((int) $entry['id'], $data['title'], $data['body'], $data['visibility'], date('Y-m-d H:i:s'));
        $this->handleUploads('entry', (int) $entry['id']);
        Flash::set('success', match ($data['visibility']) {
            'private'   => 'Личная запись сохранена — видна только вам.',
            'residents' => 'Запись обновлена и опубликована в ленте.',
            default     => 'Изменения отправлены на проверку редактору сайта.',
        });
        header('Location: /poselenie/');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $entry = $this->ownedOr404((int) $params['id']);
        $this->deleteImageFiles((int) $entry['id']);
        $this->images->deleteFor('entry', (int) $entry['id']);
        $this->diary->delete((int) $entry['id']);
        Flash::set('success', 'Запись удалена.');
        header('Location: /poselenie/');
    }

    /** Удаление одного уже загруженного фото записи (в режиме редактирования). */
    public function deletePhoto(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $entry = $this->ownedOr404((int) $params['id']);
        $imgId = (int) ($params['img'] ?? 0);
        foreach ($this->images->listFor('entry', (int) $entry['id']) as $img) {
            if ((int) $img['id'] !== $imgId) { continue; }
            $path = (string) $img['path'];
            if (!str_starts_with($path, 'tg:')) { // фото в Telegram-канале локально не хранится
                @unlink(rtrim((string) Config::get('uploads_dir'), '/\\') . '/' . basename($path));
            }
            $this->images->deleteById($imgId);
            Flash::set('info', 'Фото удалено.');
            break;
        }
        header('Location: /poselenie/dnevnik/' . (int) $entry['id'] . '/redaktirovat');
    }

    /** Удаляет физические файлы фото записи из uploads_dir (строки БД чистит deleteFor). */
    private function deleteImageFiles(int $ownerId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('entry', $ownerId) as $img) {
            $path = (string) $img['path'];
            if (str_starts_with($path, 'tg:')) { continue; } // фото в Telegram-канале, локального файла нет
            @unlink($dir . '/' . basename($path));
        }
    }

    /** @return array{0:array{title:string,body:string,visibility:string},1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $visibility = $this->pickVisibility($_POST['visibility'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Заголовок: 2–200 символов.'; }
        if (!Validator::required($body)) { $errors['body'] = 'Текст записи не может быть пустым.'; }
        return [['title' => $title, 'body' => $body, 'visibility' => $visibility], $errors];
    }

    /** private (только я) | residents (соседи) | public (все на сайте); дефолт — residents. */
    private function pickVisibility(string $v): string
    {
        return in_array($v, ['private', 'residents', 'public'], true) ? $v : 'residents';
    }

    private function handleUploads(string $ownerType, int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $files = $this->normalizeFiles($_FILES['photos']);
        $sort = count($this->images->listFor($ownerType, $ownerId));
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            // Фото дневника уходят в общий Telegram-канал (как новости) и отдаются через /tg-media/.
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) {
                $this->images->add($ownerType, $ownerId, 'tg:' . $fileId, $sort++);
                continue;
            }
            // Фолбэк на локальное хранилище, если Telegram недоступен.
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
