<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{BookRepository, BookLoanRepository, ImageRepository};

/**
 * Сервис обмена книгами (раздел жителей). Каталог виден только вошедшим жителям;
 * у каждой книги владелец-семья. Без модерации — новая книга сразу в каталоге.
 */
final class BookController
{
    public function __construct(
        private BookRepository $books = new BookRepository(),
        private BookLoanRepository $loans = new BookLoanRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function catalog(): void
    {
        Auth::requireLogin();
        $search = trim($_GET['q'] ?? '');
        $genre  = trim($_GET['genre'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $books = $this->books->listCatalog($search, $genre, $status);
        foreach ($books as &$b) {
            $imgs = $this->images->listFor('book', (int) $b['id']);
            $b['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($b);
        View::render('book/catalog', [
            'books'  => $books,
            'genres' => $this->books->genres(),
            'q'      => $search,
            'genre'  => $genre,
            'status' => $status,
        ], 'Книги поселения');
    }

    public function show(array $params): void
    {
        Auth::requireLogin();
        $book = $this->books->findWithOwner((int) $params['id']);
        if (!$book) {
            http_response_code(404);
            View::render('public/notfound', [], 'Книга не найдена');
            return;
        }
        $me = Auth::id();
        $active = $this->loans->activeForBook((int) $book['id']);
        $isOwner = (int) $book['family_id'] === $me;
        $canRequest = !$isOwner && $book['status'] === 'available' && $active === null;
        View::render('book/show', [
            'book'       => $book,
            'images'     => $this->images->listFor('book', (int) $book['id']),
            'active'     => $active,
            'isOwner'    => $isOwner,
            'canRequest' => $canRequest,
            'history'    => $isOwner ? $this->loans->historyForBook((int) $book['id']) : [],
        ], $book['title']);
    }

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('book/form', ['book' => null, 'images' => [], 'genres' => $this->books->genres(), 'errors' => []], 'Новая книга');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('book/form', ['book' => $data, 'images' => [], 'genres' => $this->books->genres(), 'errors' => $errors], 'Новая книга');
            return;
        }
        $id = $this->books->create(Auth::id(), $data['title'], $data['author'], $data['genre'], $data['description'], $data['condition_note'], date('Y-m-d H:i:s'));
        $this->handleUploads($id);
        Flash::set('success', 'Книга добавлена в каталог.');
        header('Location: /poselenie/knigi/' . $id);
    }

    public function showEdit(array $params): void
    {
        Auth::requireLogin();
        $book = $this->ownedOr404((int) $params['id']);
        View::render('book/form', [
            'book'   => $book,
            'images' => $this->images->listFor('book', (int) $book['id']),
            'genres' => $this->books->genres(),
            'errors' => [],
        ], 'Редактирование книги');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $book = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $book['id'];
            View::render('book/form', ['book' => $data, 'images' => $this->images->listFor('book', (int) $book['id']), 'genres' => $this->books->genres(), 'errors' => $errors], 'Редактирование книги');
            return;
        }
        $this->books->update((int) $book['id'], $data['title'], $data['author'], $data['genre'], $data['description'], $data['condition_note'], date('Y-m-d H:i:s'));
        $this->handleUploads((int) $book['id']);
        Flash::set('success', 'Изменения сохранены.');
        header('Location: /poselenie/knigi/' . $book['id']);
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $book = $this->ownedOr404((int) $params['id']);
        $this->deleteImageFiles((int) $book['id']);
        $this->images->deleteFor('book', (int) $book['id']);
        $this->books->delete((int) $book['id']); // book_loans уходят каскадом FK
        Flash::set('info', 'Книга удалена из каталога.');
        header('Location: /poselenie/knigi/moi');
    }

    /** Скрыть/показать книгу (только когда она не на руках). */
    public function toggleHidden(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $book = $this->ownedOr404((int) $params['id']);
        if ($book['status'] === 'on_loan') {
            Flash::set('error', 'Нельзя скрыть книгу, пока она на руках.');
        } else {
            $this->books->setStatus((int) $book['id'], $book['status'] === 'hidden' ? 'available' : 'hidden');
            Flash::set('success', $book['status'] === 'hidden' ? 'Книга снова в каталоге.' : 'Книга скрыта из каталога.');
        }
        header('Location: /poselenie/knigi/moi');
    }

    /** Переключить «недоступна/повреждена» ⇄ «готова к выдаче» (только когда не на руках). */
    public function toggleMaintenance(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $book = $this->ownedOr404((int) $params['id']);
        if ($book['status'] === 'on_loan') {
            Flash::set('error', 'Книга сейчас на руках.');
        } else {
            $this->books->setStatus((int) $book['id'], $book['status'] === 'maintenance' ? 'available' : 'maintenance');
            Flash::set('success', $book['status'] === 'maintenance' ? 'Книга готова к выдаче.' : 'Книга помечена недоступной.');
        }
        header('Location: /poselenie/knigi/moi');
    }

    public function mine(): void
    {
        Auth::requireLogin();
        $me = Auth::id();
        $myBooks = $this->books->listByFamily($me);
        foreach ($myBooks as &$b) {
            $active = $this->loans->activeForBook((int) $b['id']);
            $b['holder'] = $active['borrower_name'] ?? null;
        }
        unset($b);
        View::render('book/mine', [
            'books'      => $myBooks,
            'incoming'   => $this->loans->listIncoming($me, ['requested', 'on_loan']),
            'borrowings' => $this->loans->listByBorrower($me),
        ], 'Мои книги');
    }

    // --- helpers ---

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $title  = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $genre  = trim($_POST['genre'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $cond   = trim($_POST['condition_note'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 250)) { $errors['title'] = 'Название: 2–250 символов.'; }
        if ($genre !== '' && !Validator::length($genre, 1, 80)) { $errors['genre'] = 'Жанр до 80 символов.'; }
        return [[
            'title'  => $title,
            'author' => mb_substr($author, 0, 200),
            'genre'  => mb_substr($genre, 0, 80),
            'description'    => $desc !== '' ? $desc : null,
            'condition_note' => $cond !== '' ? mb_substr($cond, 0, 200) : null,
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
        $sort = count($this->images->listFor('book', $ownerId));
        foreach ($files as $file) {
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) { $this->images->add('book', $ownerId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    private function deleteImageFiles(int $ownerId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('book', $ownerId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    private function ownedOr404(int $id): array
    {
        $b = $this->books->findById($id);
        if (!$b || (int) $b['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Книга не найдена');
            exit;
        }
        return $b;
    }
}
