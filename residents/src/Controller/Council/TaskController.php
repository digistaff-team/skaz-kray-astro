<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, CouncilData, Csrf, Flash, View, TelegramBot, TelegramMedia, Config, Upload};
use SkazResidents\Repository\{CouncilTaskRepository, CouncilMemberRepository, CouncilLedgerRepository, CouncilCategoryRepository, ImageRepository};
use SkazResidents\Service\CouncilExpenseApproval;

/**
 * Доска текущих задач совета. Все залогиненные члены совета могут добавлять
 * задачи, брать их в работу, отмечать выполненными, править и удалять
 * (совместная модель, как в эталоне-портале).
 */
final class TaskController
{
    private const LAYOUT     = 'council/layout';
    private const PRIORITIES = ['низкая', 'средняя', 'высокая'];
    private const STATUSES   = ['новая', 'в работе', 'выполнена'];
    private const SORTS      = ['priority', 'created', 'progress', 'spent'];
    /** Фото задач лежат в общей таблице images под этим owner_type. */
    private const PHOTO_OWNER = 'task';
    private const MAX_PHOTOS  = 10;

    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository(),
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private CouncilCategoryRepository $cats = new CouncilCategoryRepository(),
        private CouncilExpenseApproval $approval = new CouncilExpenseApproval(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireLogin();
        $sort = (string) ($_GET['sort'] ?? 'priority');
        if (!in_array($sort, self::SORTS, true)) { $sort = 'priority'; }

        $active  = $this->tasks->listWithSubtasks(false, $sort);
        $archive = $this->tasks->listWithSubtasks(true, $sort);
        // Фото задач — одним запросом на весь список (без N+1).
        $photos = $this->images->listForMany(self::PHOTO_OWNER, array_map(
            static fn(array $t): int => (int) $t['id'], array_merge($active, $archive)
        ));
        foreach ($active as &$t)  { $t['photos'] = $photos[(int) $t['id']] ?? []; }
        unset($t);
        foreach ($archive as &$t) { $t['photos'] = $photos[(int) $t['id']] ?? []; }
        unset($t);

        View::render('council/tasks', [
            'active'      => $active,
            'archive'     => $archive,
            'sort'        => $sort,
            'me'          => CouncilAuth::name(),
            'members'     => CouncilData::members(),
            'expenseCats' => $this->cats->listByKind('expense', true),
        ], 'Текущие задачи', self::LAYOUT);
    }

    public function create(): void
    {
        $this->guard();
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            Flash::set('error', 'Название задачи не может быть пустым.');
            $this->back();
            return;
        }
        $priority = $this->pickPriority($_POST['priority'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $assignee = trim($_POST['assignee'] ?? '');
        $id = $this->tasks->create(
            mb_substr($title, 0, 300),
            $desc !== '' ? $desc : null,
            trim($_POST['author'] ?? '') ?: CouncilAuth::name(),
            $assignee,
            $priority,
            $this->pickDate($_POST['due_date'] ?? '')
        );
        $patch = [];
        if (isset($_POST['status']) && in_array($_POST['status'], self::STATUSES, true)) {
            $patch['status'] = $_POST['status'];
            if ($_POST['status'] === 'выполнена') { $patch['progress'] = 100; }
        }
        if (isset($_POST['spent'])) { $patch['spent'] = max(0, (float) str_replace(',', '.', (string) $_POST['spent'])); }
        $patch['expense_category_id'] = $this->pickCategory($_POST['expense_category_id'] ?? null);
        $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s'));
        $this->approval->sync($id);
        $this->handleUploads($id);
        Flash::set('success', 'Задача добавлена.');
        $this->back();
        $this->notifyAssignee($id, $assignee, mb_substr($title, 0, 300), $priority, $this->pickDate($_POST['due_date'] ?? ''));
        $this->approval->requestIfNeeded($id);
    }

    public function update(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $task = $this->tasks->find($id);
        if (!$task) { $this->back(); return; }
        $oldAssignee = (string) ($task['assignee'] ?? '');

        $patch = [];
        if (isset($_POST['title']))       { $patch['title']       = mb_substr(trim($_POST['title']), 0, 300) ?: 'Без названия'; }
        if (isset($_POST['assignee']))    { $patch['assignee']    = trim($_POST['assignee']); }
        if (isset($_POST['author']))      { $patch['author']      = trim($_POST['author']); }
        if (isset($_POST['description'])) { $patch['description']  = trim($_POST['description']) ?: null; }
        if (isset($_POST['contacts']))    { $patch['contacts']    = trim($_POST['contacts']) ?: null; }
        if (isset($_POST['links']))       { $patch['links']       = trim($_POST['links']) ?: null; }
        if (isset($_POST['progress']))    { $patch['progress']    = (int) $_POST['progress']; }
        if (isset($_POST['spent']))       { $patch['spent']       = max(0, (float) str_replace(',', '.', (string) $_POST['spent'])); }
        if (isset($_POST['due_date']))    { $patch['due_date']    = $this->pickDate($_POST['due_date']); }
        if (isset($_POST['priority']) && in_array($_POST['priority'], self::PRIORITIES, true)) { $patch['priority'] = $_POST['priority']; }
        if (isset($_POST['status'])   && in_array($_POST['status'],   self::STATUSES,   true)) {
            $patch['status'] = $_POST['status'];
            if ($_POST['status'] === 'выполнена' && !isset($patch['progress'])) { $patch['progress'] = 100; }
        }
        if (isset($_POST['expense_category_id'])) { $patch['expense_category_id'] = $this->pickCategory($_POST['expense_category_id']); }

        $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s'));
        $this->approval->sync($id);
        Flash::set('success', 'Задача обновлена.');
        $this->back();
        if (isset($patch['assignee']) && $patch['assignee'] !== $oldAssignee) {
            $this->notifyAssignee(
                $id,
                (string) $patch['assignee'],
                (string) ($patch['title'] ?? $task['title']),
                (string) ($patch['priority'] ?? $task['priority']),
                $patch['due_date'] ?? ($task['due_date'] ?? null)
            );
        }
        $this->approval->requestIfNeeded($id);
    }

    public function take(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $task = $this->tasks->find($id);
        if ($task) {
            $patch = ['assignee' => CouncilAuth::name()];
            if ($task['status'] === 'новая') { $patch['status'] = 'в работе'; }
            $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s'));
            Flash::set('success', 'Задача закреплена за вами.');
        }
        $this->back();
    }

    public function done(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if ($this->tasks->find($id)) {
            $this->tasks->updateFields($id, ['status' => 'выполнена', 'progress' => 100], date('Y-m-d H:i:s'));
            $this->approval->sync($id);
            Flash::set('success', 'Задача перенесена в архив выполненных.');
        }
        $this->back();
        $this->approval->requestIfNeeded($id);
    }

    public function reopen(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if ($this->tasks->find($id)) {
            $this->tasks->updateFields($id, ['status' => 'в работе', 'progress' => 50], date('Y-m-d H:i:s'));
            $this->approval->sync($id);
            Flash::set('info', 'Задача возвращена в работу.');
        }
        $this->back();
    }

    public function delete(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $this->approval->cancelPending($id, 'запрос отменён: задача удалена');
        $this->deletePhotoFiles($id);
        $this->images->deleteFor(self::PHOTO_OWNER, $id);
        $this->ledger->deleteByTask($id);
        $this->tasks->delete($id);
        Flash::set('info', 'Задача удалена.');
        $this->back();
    }

    // --- Подзадачи ---

    public function addSubtask(array $params = []): void
    {
        $this->guard();
        $taskId = (int) ($params['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        if ($title === '') { $title = 'Новая подзадача'; }
        if ($this->tasks->find($taskId)) {
            $this->tasks->addSubtask($taskId, mb_substr($title, 0, 300));
        }
        $this->back();
    }

    public function toggleSubtask(array $params = []): void
    {
        $this->guard();
        $id  = (int) ($params['id'] ?? 0);
        $sub = $this->tasks->findSubtask($id);
        if ($sub) {
            $this->tasks->toggleSubtask($id, (int) $sub['done'] === 0);
        }
        $this->back();
    }

    public function renameSubtask(array $params = []): void
    {
        $this->guard();
        $id    = (int) ($params['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($title !== '' && $this->tasks->findSubtask($id)) {
            $this->tasks->renameSubtask($id, mb_substr($title, 0, 300));
        }
        $this->back();
    }

    public function deleteSubtask(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $this->tasks->deleteSubtask($id);
        $this->back();
    }

    // --- Фото задач ---

    /** Удаляет одно фото задачи (кнопка × на миниатюре). */
    public function deletePhoto(array $params = []): void
    {
        $this->guard();
        $id    = (int) ($params['id'] ?? 0);
        $imgId = (int) ($params['img'] ?? 0);
        if ($this->tasks->find($id)) {
            foreach ($this->images->listFor(self::PHOTO_OWNER, $id) as $img) {
                if ((int) $img['id'] !== $imgId) { continue; }
                @unlink($this->uploadsDir() . '/' . basename((string) $img['path']));
                $this->images->deleteById($imgId);
                Flash::set('info', 'Фото удалено.');
                break;
            }
        }
        $this->back();
    }

    /**
     * Загружает фото, приложенные к форме задачи: валидация и запись в общую
     * таблицу images. Фото уходит в общий Telegram-канал (как дневник и новости)
     * и отдаётся через /tg-media/<file_id>; при недоступности Telegram — фолбэк
     * на локальное хранилище (uploads_dir).
     */
    private function handleUploads(int $taskId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $sort = count($this->images->listFor(self::PHOTO_OWNER, $taskId));
        foreach ($this->normalizeFiles($_FILES['photos']) as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            if ($sort >= self::MAX_PHOTOS) {
                Flash::set('error', 'На задачу не более ' . self::MAX_PHOTOS . ' фото.');
                break;
            }
            // Фото задачи уходит в общий Telegram-канал (как дневник и новости)
            // и отдаётся через /tg-media/.
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) {
                $this->images->add(self::PHOTO_OWNER, $taskId, 'tg:' . $fileId, $sort++);
                continue;
            }
            // Фолбэк на локальное хранилище, если Telegram недоступен.
            [$name, $err] = Upload::saveImage($file, $this->uploadsDir());
            if ($name !== null) { $this->images->add(self::PHOTO_OWNER, $taskId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    /** Физически удаляет файлы фото задачи (строки БД чистит images->deleteFor). */
    private function deletePhotoFiles(int $taskId): void
    {
        $dir = $this->uploadsDir();
        foreach ($this->images->listFor(self::PHOTO_OWNER, $taskId) as $img) {
            $path = (string) $img['path'];
            if (str_starts_with($path, 'tg:')) { continue; } // фото в Telegram, файла на диске нет
            @unlink($dir . '/' . basename($path));
        }
    }

    private function uploadsDir(): string
    {
        return rtrim((string) Config::get('uploads_dir'), '/\\');
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

    /**
     * Уведомить исполнителя в Telegram о поставленной задаче (в т.ч. самому себе —
     * чтобы можно было работать с задачей кнопками прямо в чате).
     * Отправка — ПОСЛЕ ответа клиенту (fastcgi_finish_request), чтобы возможная
     * задержка Telegram API не тормозила сохранение задачи.
     */
    /** Приоритет в мужском роде (для строки «Приоритет: …») + цветной кружок. */
    private static function priorityView(string $p): array
    {
        return [
            'высокая' => ['высокий', '🔴'],
            'средняя' => ['средний', '🟠'],
            'низкая'  => ['низкий',  '🟢'],
        ][$p] ?? [$p, '⚪'];
    }

    private function notifyAssignee(int $taskId, string $assignee, string $title, string $priority, ?string $dueDate): void
    {
        $assignee = trim($assignee);
        if ($assignee === '') { return; }

        $member = (new CouncilMemberRepository())->findByName($assignee);
        if (!$member || empty($member['telegram_id'])) { return; }

        $token = (string) (Config::get('telegram')['bot_token'] ?? '');
        if ($token === '') { return; }

        // Прямая ссылка на раздел «Текущие задачи» (deep-link Mini App), зашита в «Подробнее».
        $base = (string) (Config::get('council_app_link', 'https://t.me/SkazKray_bot/sovet') ?: 'https://t.me/SkazKray_bot/sovet');
        $link = $base . '?startapp=' . rtrim(strtr(base64_encode('/sovet/zadachi'), '+/', '-_'), '=');

        [$prioWord, $prioEmoji] = self::priorityView($priority);
        $due = ($dueDate !== null && $dueDate !== '') ? ru_date($dueDate) : 'не задан';
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Самоназначение: свой заголовок, без строки «Поставил».
        $self = ($assignee === CouncilAuth::name());
        $text = ($self ? "👋 Вы взяли себе задачу\n" : "👋 Вам поставлена задача:\n")
              . ($self ? '' : '😃 Поставил: ' . $e(CouncilAuth::name()) . "\n")
              . '🌟 ' . $e($title) . "\n"
              . $prioEmoji . ' Приоритет: ' . $e($prioWord) . "\n"
              . '⏰ Срок: ' . $e($due) . "\n\n"
              . '<a href="' . $e($link) . '">Подробнее</a>';

        // От своей задачи отказаться нельзя — для самоназначения только «Взял в работу».
        $buttons = [['text' => '✅ Взял в работу', 'callback_data' => "t:{$taskId}:take"]];
        if (!$self) {
            $buttons[] = ['text' => '🚫 Отказался', 'callback_data' => "t:{$taskId}:decline"];
        }
        $keyboard = json_encode(['inline_keyboard' => [$buttons]], JSON_UNESCAPED_UNICODE);

        if (function_exists('session_write_close')) { @session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        TelegramBot::sendMessage($token, (string) $member['telegram_id'], $text, 'HTML', $keyboard);
    }

    private function pickPriority(string $v): string
    {
        return in_array($v, self::PRIORITIES, true) ? $v : 'средняя';
    }

    /** Валидная дата YYYY-MM-DD или null. */
    private function pickDate(string $v): ?string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** id существующей расходной статьи бюджета или null. */
    private function pickCategory(mixed $raw): ?int
    {
        $id = (int) $raw;
        if ($id <= 0) { return null; }
        $cat = $this->cats->find($id);
        return ($cat && $cat['kind'] === 'expense') ? $id : null;
    }

    private function guard(): void
    {
        CouncilAuth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function back(): void
    {
        $sort = (string) ($_POST['sort'] ?? 'priority');
        if (!in_array($sort, self::SORTS, true)) { $sort = 'priority'; }
        header('Location: /sovet/zadachi?sort=' . $sort);
    }
}
