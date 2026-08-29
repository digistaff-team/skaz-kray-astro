<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Config, Mailer};
use SkazResidents\Repository\{ToolRepository, ToolLoanRepository};

/**
 * Жизненный цикл займа инструмента (P2P):
 *  - заёмщик оставляет заявку (request) и может её отменить (cancel);
 *  - владелец выдаёт (give: заявка→на руках, инструмент→on_loan) или отклоняет (decline);
 *  - владелец принимает возврат (returnLoan) с проверкой состояния (ok/broken):
 *    ok → инструмент available, broken → maintenance.
 * Уведомления по email — fail-open.
 */
final class ToolLoanController
{
    public function __construct(
        private ToolRepository $tools = new ToolRepository(),
        private ToolLoanRepository $loans = new ToolLoanRepository()
    ) {}

    public function request(array $params): void
    {
        $this->guard();
        $toolId = (int) $params['id'];
        $tool = $this->tools->findWithOwner($toolId);
        if (!$tool) { http_response_code(404); View::render('public/notfound', [], 'Инструмент не найден'); return; }

        $me = Auth::id();
        if ((int) $tool['family_id'] === $me) {
            Flash::set('error', 'Это ваш инструмент.');
            header('Location: /poselenie/instrumenty/' . $toolId); return;
        }
        if ($tool['status'] !== 'available' || $this->loans->activeForTool($toolId) !== null) {
            Flash::set('error', 'Инструмент сейчас недоступен для заявки.');
            header('Location: /poselenie/instrumenty/' . $toolId); return;
        }

        $message = trim($_POST['message'] ?? '');
        $due     = trim($_POST['due_date'] ?? '');
        $due     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;

        $this->loans->create($toolId, $me, $message !== '' ? mb_substr($message, 0, 500) : null, $due, date('Y-m-d H:i:s'));

        $this->mail($tool['owner_email'],
            'Заявка на инструмент «' . $tool['name'] . '» — Сказочный Край',
            "Здравствуйте!\n\nЖитель «" . Auth::name() . "» просит ваш инструмент «{$tool['name']}»."
            . ($due ? "\nЖелаемый срок: до {$due}." : '')
            . ($message !== '' ? "\nСообщение: {$message}" : '')
            . "\n\nОдобрить или отклонить заявку можно в разделе «Мои инструменты»:\n" . Config::get('base_url') . '/poselenie/instrumenty/moi'
        );
        Flash::set('success', 'Заявка отправлена владельцу. Он получит уведомление.');
        header('Location: /poselenie/instrumenty/' . $toolId);
    }

    public function cancel(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findById((int) $params['id']);
        if ($loan && (int) $loan['borrower_id'] === Auth::id() && $loan['status'] === 'requested') {
            $this->loans->cancel((int) $loan['id']);
            Flash::set('info', 'Заявка отменена.');
        }
        header('Location: /poselenie/instrumenty/moi');
    }

    public function give(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'requested') {
            Flash::set('error', 'Заявка уже обработана.');
            header('Location: /poselenie/instrumenty/moi'); return;
        }
        $this->loans->give((int) $loan['id'], date('Y-m-d H:i:s'));
        $this->tools->setStatus((int) $loan['tool_id'], 'on_loan');
        $this->mail($loan['borrower_email'],
            'Инструмент «' . $loan['tool_name'] . '» выдан — Сказочный Край',
            "Здравствуйте!\n\nВладелец одобрил вашу заявку на «{$loan['tool_name']}».\nКонтакт владельца: {$loan['owner_email']} ({$loan['owner_name']}).\n\nДоговоритесь о передаче. После возврата владелец отметит инструмент возвращённым."
        );
        Flash::set('success', 'Инструмент отмечен выданным.');
        header('Location: /poselenie/instrumenty/moi');
    }

    public function decline(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'requested') {
            header('Location: /poselenie/instrumenty/moi'); return;
        }
        $this->loans->decline((int) $loan['id'], date('Y-m-d H:i:s'));
        $this->mail($loan['borrower_email'],
            'Заявка на «' . $loan['tool_name'] . '» отклонена — Сказочный Край',
            "Здравствуйте!\n\nК сожалению, владелец отклонил вашу заявку на инструмент «{$loan['tool_name']}»."
        );
        Flash::set('info', 'Заявка отклонена.');
        header('Location: /poselenie/instrumenty/moi');
    }

    public function returnLoan(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'on_loan') {
            header('Location: /poselenie/instrumenty/moi'); return;
        }
        $condition = ($_POST['condition'] ?? '') === 'broken' ? 'broken' : 'ok';
        $note = trim($_POST['note'] ?? '');
        $this->loans->markReturned((int) $loan['id'], $condition, $note !== '' ? mb_substr($note, 0, 500) : null, date('Y-m-d H:i:s'));
        $this->tools->setStatus((int) $loan['tool_id'], $condition === 'broken' ? 'maintenance' : 'available');
        Flash::set('success', $condition === 'broken'
            ? 'Возврат принят. Инструмент помечён на обслуживании.'
            : 'Возврат принят. Инструмент снова доступен.');
        header('Location: /poselenie/instrumenty/moi');
    }

    // --- helpers ---

    private function guard(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    /** Проверяет, что текущий житель — владелец инструмента этого займа. */
    private function ownerOf(?array $loan): bool
    {
        if (!$loan) { http_response_code(404); View::render('public/notfound', [], 'Заявка не найдена'); return false; }
        if ((int) $loan['owner_id'] !== Auth::id()) { http_response_code(403); exit('Доступ запрещён.'); }
        return true;
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try { Mailer::send($to, $subject, $body); }
        catch (\Throwable $e) { error_log('tool loan mail failed: ' . $e->getMessage()); }
    }
}
