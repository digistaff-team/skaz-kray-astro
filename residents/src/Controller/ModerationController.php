<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Config, Mailer};
use SkazResidents\Repository\{FamilyRepository, DiaryRepository, ProductRepository};

final class ModerationController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository(),
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository()
    ) {}

    public function index(): void
    {
        Auth::requireEditor();
        View::render('moderation/index', [
            'pendingFamilies' => $this->families->listByStatus('pending'),
            'activeFamilies'  => $this->families->listByStatus('active'),
            'pendingEntries'  => $this->diary->listPending(),
            'pendingProducts' => $this->products->listPending(),
        ], 'Модерация');
    }

    public function approveFamily(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $this->families->approve($id, date('Y-m-d H:i:s'));
            $this->mail($family['email'], 'Заявка одобрена — Сказочный Край',
                "Здравствуйте!\n\nВаша заявка одобрена. Теперь вы можете войти в кабинет жителя:\n" . Config::get('base_url') . "/poselenie/vhod");
            Flash::set('success', 'Семья одобрена.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectFamily(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $this->families->setStatus($id, 'blocked');
            Flash::set('info', 'Заявка отклонена (аккаунт заблокирован).');
        }
        header('Location: /poselenie/moderation');
    }

    public function resetPassword(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $newPass = bin2hex(random_bytes(9)); // 18 hex-символов (~72 бита)
            $this->families->updatePassword($id, Auth::hash($newPass));
            Flash::set('success', "Новый пароль для «{$family['name']}»: $newPass — передайте его семье.");
        }
        header('Location: /poselenie/moderation');
    }

    public function approveEntry(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $entry = $this->diary->findById($id);
        if ($entry) {
            $this->diary->approve($id, date('Y-m-d H:i:s'));
            $this->notifyOwnerDiary($entry, 'опубликована', null);
            Flash::set('success', 'Запись опубликована.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectEntry(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $entry = $this->diary->findById($id);
        if ($entry) {
            $this->diary->reject($id, $reason !== '' ? $reason : 'Без указания причины');
            $this->notifyOwnerDiary($entry, 'отклонена', $reason);
            Flash::set('info', 'Запись отклонена.');
        }
        header('Location: /poselenie/moderation');
    }

    public function approveProduct(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $p = $this->products->findById($id);
        if ($p) {
            $this->products->approve($id, date('Y-m-d H:i:s'));
            $this->notifyOwnerProduct($p, 'опубликован', null);
            Flash::set('success', 'Товар опубликован.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectProduct(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $p = $this->products->findById($id);
        if ($p) {
            $this->products->reject($id, $reason !== '' ? $reason : 'Без указания причины');
            $this->notifyOwnerProduct($p, 'отклонён', $reason);
            Flash::set('info', 'Товар отклонён.');
        }
        header('Location: /poselenie/moderation');
    }

    private function guard(): void
    {
        Auth::requireEditor();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function notifyOwnerDiary(array $entry, string $verb, ?string $reason): void
    {
        $family = $this->families->findById((int) $entry['family_id']);
        if (!$family) { return; }
        $body = "Здравствуйте!\n\nВаша запись дневника «{$entry['title']}» $verb.";
        if ($reason) { $body .= "\nПричина: $reason\nВы можете исправить и отправить снова в личном кабинете."; }
        $this->mail($family['email'], "Дневник: запись $verb — Сказочный Край", $body);
    }

    private function notifyOwnerProduct(array $product, string $verb, ?string $reason): void
    {
        $family = $this->families->findById((int) $product['family_id']);
        if (!$family) { return; }
        $body = "Здравствуйте!\n\nВаш товар/услуга «{$product['title']}» $verb.";
        if ($reason) { $body .= "\nПричина: $reason\nВы можете исправить и отправить снова в личном кабинете."; }
        $this->mail($family['email'], "Ярмарка: объявление $verb — Сказочный Край", $body);
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try { Mailer::send($to, $subject, $body); }
        catch (\Throwable $e) { error_log('moderation mail failed: ' . $e->getMessage()); }
    }
}
