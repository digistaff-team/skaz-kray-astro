<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, CouncilData, Csrf, Flash, View, TelegramBot, Config};
use SkazResidents\Repository\{CouncilTaskRepository, CouncilMemberRepository};

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
            'members' => CouncilData::members(),
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
        if ($patch) { $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s')); }
        Flash::set('success', 'Задача добавлена.');
        $this->back();
        $this->notifyAssignee($id, $assignee, mb_substr($title, 0, 300), $priority, $this->pickDate($_POST['due_date'] ?? ''));
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

        $this->tasks->updateFields($id, $patch, date('Y-m-d H:i:s'));
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

        $text = "👋 Вам поставлена задача:\n"
              . '😃 Поставил: ' . $e(CouncilAuth::name()) . "\n"
              . '🌟 ' . $e($title) . "\n"
              . $prioEmoji . ' Приоритет: ' . $e($prioWord) . "\n"
              . '⏰ Срок: ' . $e($due) . "\n\n"
              . '<a href="' . $e($link) . '">Подробнее</a>';

        // От своей задачи отказаться нельзя — для самоназначения только «Взял в работу».
        $buttons = [['text' => '✅ Взял в работу', 'callback_data' => "t:{$taskId}:take"]];
        if ($assignee !== CouncilAuth::name()) {
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
