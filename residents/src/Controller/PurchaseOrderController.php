<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View};
use SkazResidents\Repository\{PurchaseRepository, PurchaseOrderRepository};
use SkazResidents\Service\PurchaseNotify;

/**
 * Участие в закупке: житель записывается сам, пока идёт сбор, и сам же меняет
 * количество или выходит — организатор не должен быть узким местом при наборе
 * пула. После перевода закупки в «заказано» состав заморожен: иначе он
 * разъедется с тем, что реально заказано у поставщика.
 *
 * Оплату отмечает организатор (сами переводы — вне портала, как везде в разделе
 * жителей).
 */
final class PurchaseOrderController
{
    use RequiresHousehold;

    public function __construct(
        private PurchaseRepository $purchases = new PurchaseRepository(),
        private PurchaseOrderRepository $orders = new PurchaseOrderRepository()
    ) {}

    /** Записаться в закупку или поправить своё количество. */
    public function place(array $params): void
    {
        $this->guard();
        $purchase = $this->purchaseOr404((int) $params['id']);
        $id = (int) $purchase['id'];

        if ($purchase['status'] !== PurchaseRepository::OPEN) {
            Flash::set('error', 'Сбор закрыт — состав участников уже передан поставщику.');
            header('Location: /poselenie/zakupki/' . $id);
            return;
        }
        $qty = self::qty((string) ($_POST['qty'] ?? ''));
        if ($qty === null) {
            Flash::set('error', 'Укажите количество числом, например 5 или 2.5.');
            header('Location: /poselenie/zakupki/' . $id);
            return;
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        $me   = Auth::id();
        $isNew = $this->orders->findFor($id, $me) === null;

        $this->orders->place($id, $me, $qty, $note !== '' ? mb_substr($note, 0, 500) : null, date('Y-m-d H:i:s'));
        Flash::set('success', $isNew ? 'Вы записаны в закупку.' : 'Ваша заявка обновлена.');
        header('Location: /poselenie/zakupki/' . $id);

        // Организатору сообщаем только о новых участниках: правка количества —
        // рутина, из-за которой не стоит дёргать человека каждый раз.
        if ($isNew) {
            PurchaseNotify::joined(
                (int) $purchase['organizer_id'], (string) $purchase['title'],
                Auth::name(), $qty, (string) $purchase['unit']
            );
        }
    }

    /** Выйти из закупки (пока идёт сбор). */
    public function withdraw(array $params): void
    {
        $this->guard();
        $purchase = $this->purchaseOr404((int) $params['id']);
        $id = (int) $purchase['id'];

        if ($purchase['status'] !== PurchaseRepository::OPEN) {
            Flash::set('error', 'Сбор закрыт — снять заявку уже нельзя, напишите организатору.');
            header('Location: /poselenie/zakupki/' . $id);
            return;
        }
        $order = $this->orders->findFor($id, Auth::id());
        if ($order) {
            $this->orders->remove((int) $order['id']);
            Flash::set('info', 'Вы вышли из закупки.');
        }
        header('Location: /poselenie/zakupki/' . $id);

        if ($order) {
            PurchaseNotify::left((int) $purchase['organizer_id'], (string) $purchase['title'], Auth::name());
        }
    }

    /** Отметить оплату участника (только организатор). */
    public function togglePaid(array $params): void
    {
        $this->guard();
        $purchase = $this->purchaseOr404((int) $params['id']);
        if ((int) $purchase['organizer_id'] !== Auth::id()) { http_response_code(403); exit('Доступ запрещён.'); }

        $order = $this->orders->findById((int) $params['order']);
        if (!$order || (int) $order['purchase_id'] !== (int) $purchase['id']) {
            http_response_code(404);
            View::render('public/notfound', [], 'Заявка не найдена');
            return;
        }
        $paid = ($order['paid_at'] ?? null) === null;   // была не отмечена — отмечаем, и наоборот
        $this->orders->setPaid((int) $order['id'], $paid, date('Y-m-d H:i:s'));
        Flash::set('success', $paid ? 'Оплата отмечена.' : 'Отметка об оплате снята.');
        header('Location: /poselenie/zakupki/' . $purchase['id']);
    }

    // --- helpers ---

    private function guard(): void
    {
        $this->requireHousehold('zakupki');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function purchaseOr404(int $id): array
    {
        $p = $this->purchases->findById($id);
        if (!$p) {
            http_response_code(404);
            View::render('public/notfound', [], 'Закупка не найдена');
            exit;
        }
        return $p;
    }

    /** «2,5» → «2.5»; ноль, минус и мусор — null. */
    private static function qty(string $raw): ?string
    {
        $v = str_replace([' ', ','], ['', '.'], trim($raw));
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v)) { return null; }
        return (float) $v > 0 ? $v : null;
    }
}
