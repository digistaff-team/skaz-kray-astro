<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, TelegramBot};
use SkazResidents\Repository\{CouncilTaskRepository, CouncilLedgerRepository, CouncilMemberRepository};

/**
 * Одобрение расходов по задачам Совета казначеем (Сергей Шубин) через бота.
 *
 * Поток: задача с суммой (spent) и статьёй расхода (expense_category_id) уходит
 * казначею в Telegram с кнопками «Одобрить»/«Отклонить». Расход попадает в бюджет
 * (council_ledger_entries) ТОЛЬКО после «Одобрить»; «Отклонить» — исполнителю
 * уходит уведомление.
 *
 * Момент запроса задаёт council_tasks.expense_timing:
 *   post (по умолчанию) — возмещение расходов: запрос уходит, когда задача отмечена «выполнена»;
 *   pre                 — финансирование предоплаты: запрос уходит сразу, как задача сохранена.
 *
 * council_tasks.expense_status: none → pending → approved | rejected.
 * Единственная точка записи расхода задачи в бюджет — sync() (гейт по approved).
 */
final class CouncilExpenseApproval
{
    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository(),
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    /** У задачи есть расход, подлежащий одобрению: указаны и сумма, и статья. */
    public function hasExpense(array $task): bool
    {
        return (float) ($task['spent'] ?? 0) > 0 && (int) ($task['expense_category_id'] ?? 0) > 0;
    }

    /** Предоплата: деньги нужны заранее, запрос казначею не ждёт выполнения задачи. */
    public function isPrepaid(array $task): bool
    {
        return (string) ($task['expense_timing'] ?? 'post') === 'pre';
    }

    /**
     * По задаче настал момент просить деньги: расход указан (сумма + статья) и
     * предоплата — сразу, постоплата — только когда задача выполнена.
     */
    public function needsRequest(array $task): bool
    {
        if (!$this->hasExpense($task)) { return false; }
        return $this->isPrepaid($task) || (string) $task['status'] === 'выполнена';
    }

    /**
     * Синхронизировать расход задачи с бюджетом. Операция создаётся/обновляется
     * ТОЛЬКО когда расход одобрен; иначе удаляется. Вызывается после любого
     * изменения задачи — единственная точка записи в бюджет из задач.
     */
    public function sync(int $taskId): void
    {
        $task = $this->tasks->find($taskId);
        if (!$task) { $this->ledger->deleteByTask($taskId); return; }

        // Висящий запрос на одобрение больше не актуален (убрали расход, задачу
        // с постоплатой вернули в работу) — переписать сообщение казначею и снять pending.
        if ((string) ($task['expense_status'] ?? '') === 'pending' && !$this->needsRequest($task)) {
            $this->cancelPending($taskId, 'запрос отменён: условия задачи изменились');
            $task = $this->tasks->find($taskId);
            if (!$task) { $this->ledger->deleteByTask($taskId); return; }
        }

        if ($this->hasExpense($task) && (string) ($task['expense_status'] ?? '') === 'approved') {
            $this->ledger->upsertFromTask(
                $taskId,
                (int) $task['expense_category_id'],
                (float) $task['spent'],
                $this->expenseDate($task),
                (string) ($task['title'] ?? ''),
                (string) ($task['author'] ?? '')
            );
        } else {
            $this->ledger->deleteByTask($taskId);
        }
    }

    /**
     * Если по задаче настал момент просить деньги (см. needsRequest) и расход ещё не
     * одобрен и не отправлен на одобрение — пометить pending и отправить казначею
     * запрос с кнопками. true — запрос отправлен; false — не требуется или казначей
     * недоступен (статус pending всё равно выставлен, расход ждёт одобрения).
     */
    public function requestIfNeeded(int $taskId): bool
    {
        $task = $this->tasks->find($taskId);
        if (!$task) { return false; }
        if (!$this->needsRequest($task)) { return false; }
        $status = (string) ($task['expense_status'] ?? 'none');
        if ($status === 'approved' || $status === 'pending') { return false; }

        $this->tasks->updateFields($taskId, ['expense_status' => 'pending'], date('Y-m-d H:i:s'));
        return $this->sendRequest($task);
    }

    /** Одобрить расход задачи (кнопка казначея) — расход попадает в бюджет. */
    public function approve(int $taskId): bool
    {
        $task = $this->tasks->find($taskId);
        if (!$task || !$this->hasExpense($task)) { return false; }
        // Сообщение-запрос переписывает webhook по message_id из callback — сохранённый id больше не нужен.
        $this->tasks->updateFields($taskId, ['expense_status' => 'approved', 'expense_msg_chat_id' => null, 'expense_msg_id' => null], date('Y-m-d H:i:s'));
        $this->sync($taskId);
        return true;
    }

    /** Отклонить расход задачи (кнопка казначея): убрать из бюджета + уведомить исполнителя. */
    public function reject(int $taskId): bool
    {
        $task = $this->tasks->find($taskId);
        if (!$task) { return false; }
        // Сообщение-запрос переписывает webhook по message_id из callback — сохранённый id больше не нужен.
        $this->tasks->updateFields($taskId, ['expense_status' => 'rejected', 'expense_msg_chat_id' => null, 'expense_msg_id' => null], date('Y-m-d H:i:s'));
        $this->ledger->deleteByTask($taskId);
        $this->notifyAssigneeRejected($task);
        return true;
    }

    /** Имя казначея, одобряющего расходы (в council_members по нему берём telegram_id). */
    public function approverName(): string
    {
        return (string) (Config::get('council_expense_approver', 'Сергей Шубин') ?: 'Сергей Шубин');
    }

    // --- Отправка сообщений (медленные вызовы API — после ответа клиенту) ---

    private function sendRequest(array $task): bool
    {
        [$token, $chatId] = $this->approverTarget();
        if ($token === '' || $chatId === '') { return false; }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = number_format((float) $task['spent'], 0, '.', ' ');
        // Предоплата — просьба профинансировать предстоящие траты, постоплата — возместить понесённые.
        $pre = $this->isPrepaid($task);
        $text = ($pre ? '💰 Запрос на финансирование (предоплата)' : '💰 Запрос на возмещение расходов (постоплата)') . "\n"
              . ($pre ? 'Создана задача: ' : 'Выполнена задача: ') . $e((string) ($task['title'] ?? '')) . "\n"
              . 'Исполнитель: ' . $e(trim((string) ($task['assignee'] ?? '')) ?: '—') . "\n"
              . ($pre ? 'Предстоящие расходы: ' : 'Расходы: ') . $e($amount) . ' руб.';
        $id = (int) $task['id'];
        $keyboard = (string) json_encode(['inline_keyboard' => [[
            ['text' => '✅ Одобрить',  'callback_data' => "x:{$id}:approve"],
            ['text' => '❌ Отклонить', 'callback_data' => "x:{$id}:reject"],
        ]]], JSON_UNESCAPED_UNICODE);

        $this->finishRequest();
        $msgId = TelegramBot::sendMessageId($token, $chatId, $text, 'HTML', $keyboard);
        if ($msgId === null) { return false; }
        // Запоминаем сообщение, чтобы позже (решение / отмена) переписать его и убрать кнопки.
        $this->tasks->updateFields(
            (int) $task['id'],
            ['expense_msg_chat_id' => $chatId, 'expense_msg_id' => $msgId],
            date('Y-m-d H:i:s')
        );
        return true;
    }

    /**
     * Отменить висящий запрос на одобрение: снять pending и, если сообщение было
     * сохранено (expense_msg_id), переписать его казначею с причиной и убрать кнопки.
     * Идемпотентно и безопасно, когда сохранённого сообщения нет. Вызывать, когда
     * запрос протух: задачу вернули в работу, убрали расход или удаляют её.
     */
    public function cancelPending(int $taskId, string $reason): void
    {
        $task = $this->tasks->find($taskId);
        if (!$task) { return; }

        $chatId = (string) ($task['expense_msg_chat_id'] ?? '');
        $msgId  = (int) ($task['expense_msg_id'] ?? 0);

        $patch = ['expense_msg_chat_id' => null, 'expense_msg_id' => null];
        if ((string) ($task['expense_status'] ?? '') === 'pending') { $patch['expense_status'] = 'none'; }
        $this->tasks->updateFields($taskId, $patch, date('Y-m-d H:i:s'));

        if ($chatId === '' || !$msgId) { return; }
        $token = $this->botToken();
        if ($token === '') { return; }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = number_format((float) ($task['spent'] ?? 0), 0, '.', ' ');
        $text = 'Задача: ' . $e((string) ($task['title'] ?? '')) . "\n"
              . 'Расходы: ' . $e($amount) . ' руб.' . "\n\n"
              . '❌ ' . $e($reason);

        $this->finishRequest();
        TelegramBot::editMessageText($token, $chatId, $msgId, $text, 'HTML', null);
    }

    private function notifyAssigneeRejected(array $task): void
    {
        $token = $this->botToken();
        if ($token === '') { return; }
        $assignee = trim((string) ($task['assignee'] ?? ''));
        if ($assignee === '') { return; }
        $member = $this->members->findByName($assignee);
        if (!$member || empty($member['telegram_id'])) { return; }

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = ($this->isPrepaid($task)
                    ? 'Ваш запрос на финансирование предоплаты по Задаче «'
                    : 'Ваш запрос на возмещение расходов по выполнению Задачи «')
              . $e((string) ($task['title'] ?? '')) . '» отклонён. '
              . 'Свяжитесь с ' . $e($this->approverName()) . ', чтобы уточнить детали.';

        $this->finishRequest();
        TelegramBot::sendMessage($token, (string) $member['telegram_id'], $text, 'HTML');
    }

    /** @return array{0:string,1:string} [token, chatId казначея] — пусто, если недоступен. */
    private function approverTarget(): array
    {
        $token = $this->botToken();
        if ($token === '') { return ['', '']; }
        $member = $this->members->findByName($this->approverName());
        $chatId = ($member && !empty($member['telegram_id'])) ? (string) $member['telegram_id'] : '';
        return [$token, $chatId];
    }

    private function botToken(): string
    {
        $tg = Config::get('telegram');
        return is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
    }

    private function finishRequest(): void
    {
        if (function_exists('session_write_close'))    { @session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    }

    /** Дата расхода = дата выполнения → срок → дата создания (YYYY-MM-DD). */
    private function expenseDate(array $task): string
    {
        foreach (['completed_at', 'due_date', 'created_at'] as $k) {
            $v = (string) ($task[$k] ?? '');
            if ($v !== '') { return substr($v, 0, 10); }
        }
        return date('Y-m-d');
    }
}
