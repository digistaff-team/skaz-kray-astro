<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Validator};
use SkazResidents\Repository\{DeliveryRepository, TripRepository, ImageRepository};
use SkazResidents\Service\{DeliveryPolicy as P, DeliveryNotify as N, CatalogAnnounce};

/**
 * Доставка в разделе «Поездки»: просьба привезти к поездке или заявка на доску.
 * Спека: docs/superpowers/specs/2026-09-26-dostavka-design.md. Права — только
 * через DeliveryPolicy; переход статуса — атомарный UPDATE в репозитории: не
 * сработал — значит, заявку уже обработали.
 * Уведомления (DeliveryNotify::send) — последними: бот завершает ответ.
 */
final class DeliveryController
{
    use RequiresHousehold;

    public function __construct(
        private DeliveryRepository $deliveries = new DeliveryRepository(),
        private TripRepository $trips = new TripRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function board(): void
    {
        $this->requireHousehold('dostavka');
        $kind = (string) ($_GET['kind'] ?? '');
        View::render('delivery/board', [
            'items' => $this->deliveries->listBoard(date('Y-m-d'), $kind),
            'kind'  => in_array($kind, ['buy', 'pickup'], true) ? $kind : '',
        ], 'Доставка');
    }

    public function showCreate(): void
    {
        $this->requireHousehold('dostavka');
        $tripId = (int) ($_GET['poezdka'] ?? 0);
        $trip = $this->tripForRequest($tripId);
        if ($tripId > 0 && $trip === null) { $this->tripUnavailable(); return; }
        View::render('delivery/form', ['d' => ['kind' => 'buy'], 'trip' => $trip, 'errors' => []], 'Попросить привезти');
    }

    public function create(): void
    {
        $this->requireHousehold('dostavka');
        Csrf::guard();
        $tripId = (int) ($_POST['trip_id'] ?? 0);
        $trip = $this->tripForRequest($tripId);
        // Просили к поездке, а она уже недоступна (отменена, прошла, своя) — не превращаем
        // молча просьбу водителю в заявку на доску: пусть житель решит сам.
        if ($tripId > 0 && $trip === null) { $this->tripUnavailable(); return; }
        [$d, $errors] = $this->validate();
        if ($errors) {
            View::render('delivery/form', ['d' => $d, 'trip' => $trip, 'errors' => $errors], 'Попросить привезти');
            return;
        }
        $id = $this->deliveries->create(Auth::id(), $trip ? (int) $trip['id'] : null, $d['kind'], $d['what'], $d['place'],
            $d['need_by'], $d['budget'], $d['pickup_code'], $d['note'], date('Y-m-d H:i:s'));

        if ($trip) {
            Flash::set('success', 'Просьба отправлена водителю. Он получит уведомление.');
            header('Location: /poselenie/dostavka/' . $id);
            $full = $this->deliveries->findDetailed($id);
            if ($full !== null) {
                N::send((int) $trip['driver_id'], (string) $trip['driver_email'], 'Просьба привезти',
                    N::requestLines($full), 'Взять или отказаться: ', '/poselenie/dostavka/moi');
            }
            return;
        }
        Flash::set('success', 'Заявка на доске — соседи увидят её.');
        header('Location: /poselenie/dostavka/' . $id);
        CatalogAnnounce::delivery($id, $d['kind'], $d['place'], $d['need_by']);   // после ответа
    }

    public function mine(): void
    {
        $this->requireHousehold('dostavka');
        $me = Auth::id();
        View::render('delivery/mine', [
            'asked'    => $this->deliveries->listByRequester($me),
            'carrying' => $this->deliveries->listByCarrier($me),
            'incoming' => $this->deliveries->listForTripDriver($me, ['requested'], date('Y-m-d')),
        ], 'Мои доставки');
    }

    public function show(array $params): void
    {
        $this->requireHousehold('dostavka');
        $d = $this->found((int) $params['id']);
        if ($d === null) { return; }
        $me = Auth::id();
        // Не открытую заявку видят только её стороны. Прочие приходят обычно по старой
        // ссылке из анонса — говорим, что её уже нет на доске, и ведём на доску.
        if (!P::canView($d, $me, self::driverOf($d))) {
            $this->back((int) $d['id'], 'info', 'Эта заявка уже не на доске.', '/poselenie/dostavka'); return;
        }
        $receipts = $this->images->listFor('delivery_receipt', (int) $d['id']);
        View::render('delivery/show', [
            'd'        => $d,
            'actions'  => P::actions($d, $me, self::driverOf($d), count($receipts), self::live($d)),
            'private'  => P::seesPrivate($d, $me),
            'isRequester' => (int) $d['requester_id'] === $me,
            'receipts' => P::seesPrivate($d, $me) ? $receipts : [],
        ], delivery_kind_label((string) $d['kind']) . ': ' . $d['place']);
    }

    public function take(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::TAKE);
        if ($d === null) { return; }
        $from = $d['status'] === 'requested' ? 'requested' : 'open';
        if (!$this->deliveries->take((int) $d['id'], Auth::id(), $from, date('Y-m-d H:i:s'))) {
            // Просьбу к поездке мог опередить отказ или отмена, заявку с доски — другой сосед.
            // Не на карточку: она этому жителю может быть уже не видна.
            if ($from === 'requested') { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.', '/poselenie/dostavka/moi'); return; }
            $this->back((int) $d['id'], 'error', 'Заявку уже взяли.', '/poselenie/dostavka'); return;
        }
        $full = $this->deliveries->findDetailed((int) $d['id']);
        $contact = family_contact((string) $full['car_name'], $full['car_tg'] ?? null, (string) $full['car_email']);
        $this->back((int) $d['id'], 'success', 'Заявка ваша. Заказчик получит ваш контакт.');
        N::send((int) $d['requester_id'], (string) $full['req_email'], 'Заявку на доставку взяли',
            N::takenLines($full, $contact), 'Заявка: ', '/poselenie/dostavka/' . (int) $d['id']);
    }

    /** «Не смогу»: водитель отклоняет просьбу к поездке или исполнитель отказывается от взятой. */
    public function cantDo(array $params): void
    {
        $this->requireHousehold('dostavka');
        Csrf::guard();
        $d = $this->found((int) $params['id']);
        if ($d === null) { return; }
        $me = Auth::id();
        if (P::allows(P::DECLINE, $d, $me, self::driverOf($d), 0, self::live($d))) {
            if (!$this->deliveries->decline((int) $d['id'])) { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return; }
            $this->back((int) $d['id'], 'info', 'Вы отказались от просьбы.', '/poselenie/dostavka/moi');
            N::send((int) $d['requester_id'], (string) $d['req_email'], 'Водитель не сможет привезти',
                N::driverDeclinedLines($d), 'Можно выложить заявку на общую доску: ', '/poselenie/dostavka/' . (int) $d['id']);
            return;
        }
        if (P::allows(P::DROP, $d, $me, self::driverOf($d), 0, self::live($d))) {
            if (!$this->deliveries->releaseCarrier((int) $d['id'], $me)) { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return; }
            $this->back((int) $d['id'], 'info', 'Заявка вернулась на доску.', '/poselenie/dostavka/moi');
            N::send((int) $d['requester_id'], (string) $d['req_email'], 'Исполнитель не сможет привезти',
                N::droppedLines($d), 'Заявка: ', '/poselenie/dostavka/' . (int) $d['id']);
            return;
        }
        $this->deny($d, $d['status'] === 'accepted' ? P::DROP : P::DECLINE);
    }

    public function unassign(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::UNASSIGN);
        if ($d === null) { return; }
        $carrier = (int) $d['carrier_id'];
        // Снимаем именно того, кого видел заказчик: устаревшая форма не снимет нового исполнителя.
        if (!$this->deliveries->releaseCarrier((int) $d['id'], $carrier)) { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return; }
        $this->back((int) $d['id'], 'info', 'Исполнитель снят, заявка снова на доске.');
        N::send($carrier, (string) $d['car_email'], 'Вас сняли с заявки на доставку',
            N::unassignedLines($d), 'Другие заявки: ', '/poselenie/dostavka');
    }

    public function toBoard(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::TO_BOARD);
        if ($d === null) { return; }
        if (!$this->deliveries->toBoard((int) $d['id'])) { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return; }
        $this->back((int) $d['id'], 'success', 'Заявка на доске — соседи увидят её.');
        CatalogAnnounce::delivery((int) $d['id'], (string) $d['kind'], (string) $d['place'], $d['need_by'] ?? null);
        // Просьба застряла у неактивной/прошедшей поездки — водителю сказать, что её забрали.
        // После declined водитель сам отказался, ему сообщать нечего.
        $driver = self::driverOf($d);
        if ($d['status'] === 'requested' && $driver !== null) {
            N::send($driver, (string) $d['driver_email'], 'Просьбу забрали на общую доску',
                N::withdrawnLines($d), 'Другие заявки: ', '/poselenie/dostavka');
        }
    }

    public function deliver(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::DELIVER);
        if ($d === null) { return; }
        $sum = null;
        if ($d['kind'] === 'buy') {
            [$sum, $err] = self::money((string) ($_POST['receipt_sum'] ?? ''));
            if ($err !== null) { $this->back((int) $d['id'], 'error', 'Сумма по чеку: ' . $err); return; }
        }
        if (!$this->deliveries->deliver((int) $d['id'], Auth::id(), $sum, date('Y-m-d H:i:s'))) {
            $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return;
        }
        // Статус уже сменён — успех сообщаем всегда; сбой фото не отменяет «Привёз».
        Flash::set('success', 'Отмечено: привезли. Заказчик получит уведомление.');
        $hadError = Flash::has('error');
        $photos = $d['kind'] === 'buy' ? $this->handleReceiptUploads((int) $d['id']) : 0;
        if (!$hadError && Flash::has('error')) { Flash::set('info', 'Фото чека можно добавить позже — на этой странице.'); }
        $full = $this->deliveries->findDetailed((int) $d['id']) ?? $d;
        header('Location: /poselenie/dostavka/' . (int) $d['id']);
        N::send((int) $d['requester_id'], (string) $d['req_email'], 'Вам привезли заказ',
            N::deliveredLines($full, $photos), 'Отметьте получение: ', '/poselenie/dostavka/' . (int) $d['id']);
    }

    public function addReceipt(array $params): void
    {
        $this->requireHousehold('dostavka');
        Csrf::guard();
        $d = $this->found((int) $params['id']);
        if ($d === null) { return; }
        $count = count($this->images->listFor('delivery_receipt', (int) $d['id']));
        if (!P::allows(P::ADD_RECEIPT, $d, Auth::id(), self::driverOf($d), $count, self::live($d))) { $this->deny($d, P::ADD_RECEIPT); return; }
        if (!self::receiptFiles()) { $this->back((int) $d['id'], 'info', 'Выберите фото чека.'); return; }
        $hadError = Flash::has('error');
        $added = $this->handleReceiptUploads((int) $d['id']);
        if ($added > 0) { Flash::set('success', $added === 1 ? 'Фото чека добавлено.' : 'Фото чека добавлены.'); }
        if (!$hadError && Flash::has('error')) { Flash::set('info', 'Не все фото загрузились — попробуйте ещё раз.'); }
        header('Location: /poselenie/dostavka/' . (int) $d['id']);
    }

    public function settle(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::SETTLE);
        if ($d === null) { return; }
        if (!$this->deliveries->settle((int) $d['id'], date('Y-m-d H:i:s'))) {
            $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return;
        }
        $this->back((int) $d['id'], 'success', 'Спасибо! Получение и расчёт подтверждены.');
        N::send((int) $d['carrier_id'], (string) $d['car_email'], 'Получение подтверждено',
            N::settledLines($d), 'Заявка: ', '/poselenie/dostavka/' . (int) $d['id']);
    }

    public function cancel(array $params): void
    {
        $d = $this->guarded((int) $params['id'], P::CANCEL);
        if ($d === null) { return; }
        if (!$this->deliveries->cancel((int) $d['id'])) { $this->back((int) $d['id'], 'error', 'Заявка уже обработана.'); return; }
        $this->back((int) $d['id'], 'info', 'Заявка отменена.', '/poselenie/dostavka/moi');
        // Кому сказать — по строке ПОСЛЕ отмены: заявку могли взять между чтением и отменой
        // (cancel carrier_id не обнуляет). Исполнитель, если есть; иначе водитель поездки.
        $fresh = $this->deliveries->findDetailed((int) $d['id']) ?? $d;
        $to = $fresh['carrier_id'] !== null ? [(int) $fresh['carrier_id'], (string) $fresh['car_email']]
            : ($fresh['trip_driver_id'] !== null ? [(int) $fresh['trip_driver_id'], (string) $fresh['driver_email']] : null);
        if ($to !== null) {
            N::send($to[0], $to[1], 'Заявка на доставку отменена', N::cancelledLines($d), 'Другие заявки: ', '/poselenie/dostavka');
        }
    }

    // --- helpers ---

    /** Заявка + проверка права на действие; иначе 403/404 и null. */
    private function guarded(int $id, string $action): ?array
    {
        $this->requireHousehold('dostavka');
        Csrf::guard();
        $d = $this->found($id);
        if ($d === null) { return null; }
        if (!P::allows($action, $d, Auth::id(), self::driverOf($d), 0, self::live($d))) { $this->deny($d, $action); return null; }
        return $d;
    }

    private function found(int $id): ?array
    {
        $d = $this->deliveries->findDetailed($id);
        if (!$d) { $this->notFound(); return null; }
        return $d;
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('public/notfound', [], 'Заявка не найдена');
    }

    /** Отказ по P::denial: 403, «уже взяли» (на доску) или «уже обработана». */
    private function deny(array $d, string $action): void
    {
        switch (P::denial($d, Auth::id(), self::driverOf($d), $action)) {
            case P::DENY_FORBIDDEN:
                // Вызывающий после deny() делает return — страница и есть весь ответ.
                http_response_code(403);
                View::render('public/forbidden', [], 'Действие недоступно');
                return;
            case P::DENY_TAKEN:
                $this->back((int) $d['id'], 'error', 'Заявку уже взяли.', '/poselenie/dostavka'); return;
            default:
                $this->back((int) $d['id'], 'error', 'Заявка уже обработана.');
        }
    }

    private function back(int $id, string $type, string $msg, ?string $to = null): void
    {
        Flash::set($type, $msg);
        header('Location: ' . ($to ?? '/poselenie/dostavka/' . $id));
    }

    private static function driverOf(array $d): ?int
    {
        return $d['trip_driver_id'] !== null ? (int) $d['trip_driver_id'] : null;
    }

    /** Поездка заявки активна и не прошла (или заявка без поездки) — см. P::tripLive. */
    private static function live(array $d): bool
    {
        return P::tripLive($d, date('Y-m-d'));
    }

    /** Поездка, к которой можно попросить привезти: чужая, активная, не прошедшая. */
    private function tripForRequest(int $tripId): ?array
    {
        if ($tripId <= 0) { return null; }
        $t = $this->trips->findWithDriver($tripId);
        if (!$t || $t['status'] !== 'active' || $t['trip_date'] < date('Y-m-d') || (int) $t['driver_id'] === Auth::id()) {
            return null;
        }
        return $t;
    }

    private function tripUnavailable(): void
    {
        Flash::set('error', 'Поездка недоступна для просьбы — выберите другую или разместите заявку на общей доске.');
        header('Location: /poselenie/dostavka/novaya');
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function validate(): array
    {
        $kind  = ($_POST['kind'] ?? '') === 'pickup' ? 'pickup' : 'buy';
        $what  = trim((string) ($_POST['what'] ?? ''));
        $place = trim((string) ($_POST['place'] ?? ''));
        $need  = trim((string) ($_POST['need_by'] ?? ''));
        $code  = trim((string) ($_POST['pickup_code'] ?? ''));
        $note  = trim((string) ($_POST['note'] ?? ''));
        $errors = [];

        if (!Validator::length($what, 3, 1000)) { $errors['what'] = 'Опишите, что ' . ($kind === 'buy' ? 'купить' : 'забрать') . ': 3–1000 символов.'; }
        if (!Validator::length($place, 2, 160)) { $errors['place'] = 'Где: 2–160 символов.'; }
        if ($need !== '' && !self::validDate($need)) { $errors['need_by'] = 'Укажите дату.'; }
        elseif ($need !== '' && $need < date('Y-m-d')) { $errors['need_by'] = 'Дата не может быть в прошлом.'; }
        $budget = null;
        if ($kind === 'buy') {
            [$budget, $err] = self::money((string) ($_POST['budget'] ?? ''));
            if ($err !== null) { $errors['budget'] = 'Примерная сумма: ' . $err; }
        }
        if ($kind === 'pickup' && mb_strlen($code) > 200) { $errors['pickup_code'] = 'Код или номер заказа: до 200 символов.'; }
        if (mb_strlen($note) > 500) { $errors['note'] = 'Комментарий: до 500 символов.'; }

        return [[
            'kind' => $kind, 'what' => $what, 'place' => $place,
            'need_by' => $need !== '' ? $need : null,
            'budget' => $budget,
            'pickup_code' => $kind === 'pickup' && $code !== '' ? $code : null,
            'note' => $note !== '' ? $note : null,
        ], $errors];
    }

    /** Дата из формы: строго Y-m-d и существующий день календаря (не 2026-02-30). */
    public static function validDate(string $s): bool
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $s, $m)) { return false; }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * «1 250,50» → «1250.50». Пусто — null без ошибки.
     * @return array{0:?string,1:?string} [сумма, ошибка]
     */
    public static function money(string $raw): array
    {
        $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($raw));
        if ($v === '') { return [null, null]; }
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $v) || (float) $v > 1_000_000) {
            return [null, 'число от 0 до 1 000 000, например 1250 или 1250,50.'];
        }
        return [number_format((float) $v, 2, '.', ''), null];
    }

    /**
     * Фото чека — как фото закупок: Telegram-канал медиа, при недоступности —
     * uploads_dir. Не больше P::MAX_RECEIPTS на заявку. Возвращает, сколько добавлено.
     */
    private function handleReceiptUploads(int $id): int
    {
        $files = self::receiptFiles();
        if (!$files) { return 0; }
        $sort = count($this->images->listFor('delivery_receipt', $id));
        $room = P::MAX_RECEIPTS - $sort;
        if (count($files) > $room) { Flash::set('info', 'Можно приложить не больше ' . P::MAX_RECEIPTS . ' фото чека.'); }
        $added = 0;
        foreach (array_slice($files, 0, max(0, $room)) as $file) {
            $fileId = \SkazResidents\TelegramMedia::upload($file);
            if ($fileId !== null) { $this->images->add('delivery_receipt', $id, 'tg:' . $fileId, $sort++); $added++; continue; }
            [$name, $err] = \SkazResidents\Upload::saveImage($file, (string) \SkazResidents\Config::get('uploads_dir'));
            if ($name !== null) { $this->images->add('delivery_receipt', $id, $name, $sort++); $added++; }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
        return $added;
    }

    /**
     * Выбранные файлы из поля photos[] (пустые слоты формы отброшены).
     * @return array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private static function receiptFiles(): array
    {
        if (empty($_FILES['photos'])) { return []; }
        $f = $_FILES['photos'];
        $files = is_array($f['name'])
            ? array_map(fn($i) => [
                'name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ], array_keys($f['name']))
            : [$f];
        return array_values(array_filter($files, fn($x) => ($x['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    }
}
