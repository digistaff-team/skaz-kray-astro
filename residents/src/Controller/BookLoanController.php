<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Config, Mailer};
use SkazResidents\Repository\{BookRepository, BookLoanRepository};

/**
 * Жизненный цикл брони книги (P2P):
 *  - читатель бронирует (request) и может отменить бронь (cancel);
 *  - владелец выдаёт (give: бронь→на руках, книга→on_loan) или отклоняет (decline);
 *  - владелец принимает возврат (returnLoan) с проверкой состояния (ok/broken):
 *    ok → книга available, broken → maintenance.
 * Уведомления по email — fail-open.
 */
final class BookLoanController
{
    public function __construct(
        private BookRepository $books = new BookRepository(),
        private BookLoanRepository $loans = new BookLoanRepository()
    ) {}

    public function request(array $params): void
    {
        $this->guard();
        $bookId = (int) $params['id'];
        $book = $this->books->findWithOwner($bookId);
        if (!$book) { http_response_code(404); View::render('public/notfound', [], 'Книга не найдена'); return; }

        $me = Auth::id();
        if ((int) $book['family_id'] === $me) {
            Flash::set('error', 'Это ваша книга.');
            header('Location: /poselenie/knigi/' . $bookId); return;
        }
        if ($book['status'] !== 'available' || $this->loans->activeForBook($bookId) !== null) {
            Flash::set('error', 'Книга сейчас недоступна для брони.');
            header('Location: /poselenie/knigi/' . $bookId); return;
        }

        $message = trim($_POST['message'] ?? '');
        $due     = trim($_POST['due_date'] ?? '');
        $due     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;

        $this->loans->create($bookId, $me, $message !== '' ? mb_substr($message, 0, 500) : null, $due, date('Y-m-d H:i:s'));

        $this->mail($book['owner_email'],
            'Бронь книги «' . $book['title'] . '» — Сказочный Край',
            "Здравствуйте!\n\nЖитель «" . Auth::name() . "» хочет прочитать вашу книгу «{$book['title']}»."
            . ($due ? "\nЖелаемый срок: до {$due}." : '')
            . ($message !== '' ? "\nСообщение: {$message}" : '')
            . "\n\nОдобрить или отклонить бронь можно в разделе «Мои книги»:\n" . Config::get('base_url') . '/poselenie/knigi/moi'
        );
        Flash::set('success', 'Бронь отправлена владельцу. Он получит уведомление.');
        header('Location: /poselenie/knigi/' . $bookId);
    }

    public function cancel(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findById((int) $params['id']);
        if ($loan && (int) $loan['borrower_id'] === Auth::id() && $loan['status'] === 'requested') {
            $this->loans->cancel((int) $loan['id']);
            Flash::set('info', 'Бронь отменена.');
        }
        header('Location: /poselenie/knigi/moi');
    }

    public function give(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'requested') {
            Flash::set('error', 'Бронь уже обработана.');
            header('Location: /poselenie/knigi/moi'); return;
        }
        $this->loans->give((int) $loan['id'], date('Y-m-d H:i:s'));
        $this->books->setStatus((int) $loan['book_id'], 'on_loan');
        $this->mail($loan['borrower_email'],
            'Книга «' . $loan['book_title'] . '» выдана — Сказочный Край',
            "Здравствуйте!\n\nВладелец одобрил вашу бронь на «{$loan['book_title']}».\nКонтакт владельца: {$loan['owner_email']} ({$loan['owner_name']}).\n\nДоговоритесь о передаче. После прочтения верните книгу владельцу."
        );
        Flash::set('success', 'Книга отмечена выданной.');
        header('Location: /poselenie/knigi/moi');
    }

    public function decline(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'requested') {
            header('Location: /poselenie/knigi/moi'); return;
        }
        $this->loans->decline((int) $loan['id'], date('Y-m-d H:i:s'));
        $this->mail($loan['borrower_email'],
            'Бронь книги «' . $loan['book_title'] . '» отклонена — Сказочный Край',
            "Здравствуйте!\n\nК сожалению, владелец отклонил вашу бронь на книгу «{$loan['book_title']}»."
        );
        Flash::set('info', 'Бронь отклонена.');
        header('Location: /poselenie/knigi/moi');
    }

    public function returnLoan(array $params): void
    {
        $this->guard();
        $loan = $this->loans->findDetailed((int) $params['id']);
        if (!$this->ownerOf($loan)) { return; }
        if ($loan['status'] !== 'on_loan') {
            header('Location: /poselenie/knigi/moi'); return;
        }
        $condition = ($_POST['condition'] ?? '') === 'broken' ? 'broken' : 'ok';
        $note = trim($_POST['note'] ?? '');
        $this->loans->markReturned((int) $loan['id'], $condition, $note !== '' ? mb_substr($note, 0, 500) : null, date('Y-m-d H:i:s'));
        $this->books->setStatus((int) $loan['book_id'], $condition === 'broken' ? 'maintenance' : 'available');
        Flash::set('success', $condition === 'broken'
            ? 'Возврат принят. Книга помечена недоступной.'
            : 'Возврат принят. Книга снова доступна.');
        header('Location: /poselenie/knigi/moi');
    }

    // --- helpers ---

    private function guard(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function ownerOf(?array $loan): bool
    {
        if (!$loan) { http_response_code(404); View::render('public/notfound', [], 'Бронь не найдена'); return false; }
        if ((int) $loan['owner_id'] !== Auth::id()) { http_response_code(403); exit('Доступ запрещён.'); }
        return true;
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try { Mailer::send($to, $subject, $body); }
        catch (\Throwable $e) { error_log('book loan mail failed: ' . $e->getMessage()); }
    }
}
