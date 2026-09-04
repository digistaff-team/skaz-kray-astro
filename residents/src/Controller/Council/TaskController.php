<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Csrf, Flash, View};
use SkazResidents\Repository\CouncilTaskRepository;

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

    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireLogin();
        $sort = (string) ($_GET['sort'] ?? 'priority');
        if (!in_array($sort, self::SORTS, true)) { $sort = 'priority'; }

        View::render('council/tasks', [
            'active'  => $this->tasks->listWithSubtasks(false, $sort),
            'archive' => $this->tasks->listWithSubtasks(true, $sort),
            'sort'    => $sort,
            'me'      => CouncilAuth::name(),
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
        $id = $this->tasks->create(
            mb_substr($title, 0, 300),
            $desc !== '' ? $desc : null,
            trim($_POST['author'] ?? '') ?: CouncilAuth::name(),
            trim($_POST['assignee'] ?? ''),
            $priority,
            $this->pickDate($_POST['due_date'] ?? '')
        );
        $patch = [];
        if (isset($_POST['status']) && in_array($_POST['status'], self::STATUSES, true)) {
            $patch['status'] = $_POST['status'];
            if ($_POST['status'] === 'выполнена') { $patch['progress'] = 100; }
        }
        if (isset($_POST['spent'])) { $patch['spent'] = max(0, (float) str_replace(',', '.', (string) $_POST['spent'])); }
        if ($patch) { $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s')); }
        Flash::set('success', 'Задача добавлена.');
        $this->back();
    }

    public function update(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if (!$this->tasks->find($id)) { $this->back(); return; }

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

        $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s'));
        Flash::set('success', 'Задача обновлена.');
        $this->back();
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
            Flash::set('success', 'Задача перенесена в архив выполненных.');
        }
        $this->back();
    }

    public function reopen(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if ($this->tasks->find($id)) {
            $this->tasks->updateFields($id, ['status' => 'в работе', 'progress' => 50], date('Y-m-d H:i:s'));
            Flash::set('info', 'Задача возвращена в работу.');
        }
        $this->back();
    }

    public function delete(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
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
