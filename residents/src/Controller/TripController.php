<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Sections};
use SkazResidents\Repository\{TripRepository, TripBookingRepository, DeliveryRepository};
use SkazResidents\Service\{CatalogAnnounce, DeliveryNotify};

/**
 * Совместные поездки (попутки) — раздел жителей. Доска предстоящих поездок
 * видна вошедшим жителям; водитель-семья публикует поездку, пассажиры бронируют.
 */
final class TripController
{
    use RequiresHousehold;

    private const MAX_SEATS = 8;

    public function __construct(
        private TripRepository $trips = new TripRepository(),
        private TripBookingRepository $bookings = new TripBookingRepository()
    ) {}

    public function board(): void
    {
        $this->requireHousehold('poezdki');
        $search = trim($_GET['q'] ?? '');
        $date   = trim($_GET['date'] ?? '');
        $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
        $trips = $this->trips->listUpcoming(date('Y-m-d'), $search, $date);
        View::render('trip/board', [
            'trips'  => $trips,
            'q'      => $search,
            'date'   => $date,
        ], 'Совместные поездки');
    }

    public function show(array $params): void
    {
        $this->requireHousehold('poezdki');
        $trip = $this->trips->findWithDriver((int) $params['id']);
        if (!$trip) {
            http_response_code(404);
            View::render('public/notfound', [], 'Поездка не найдена');
            return;
        }
        $me = Auth::id();
        $isDriver = (int) $trip['driver_id'] === $me;
        $myBooking = $this->bookings->activeForTripAndPassenger((int) $trip['id'], $me);
        $canBook = !$isDriver
            && $trip['status'] === 'active'
            && (int) $trip['seats_free'] > 0
            && $myBooking === null
            && $trip['trip_date'] >= date('Y-m-d');
        View::render('trip/show', [
            'trip'      => $trip,
            'isDriver'  => $isDriver,
            'myBooking' => $myBooking,
            'canBook'   => $canBook,
            'bookings'  => $isDriver ? $this->bookings->listForTrip((int) $trip['id']) : [],
            'maxSeats'  => min(self::MAX_SEATS, (int) $trip['seats_free']),
            'canAskDelivery' => !$isDriver && $trip['status'] === 'active' && $trip['trip_date'] >= date('Y-m-d')
                && Sections::isEnabled('dostavka'),
        ], $trip['origin'] . ' → ' . $trip['destination']);
    }

    public function showCreate(): void
    {
        $this->requireHousehold('poezdki');
        View::render('trip/form', ['trip' => null, 'errors' => []], 'Новая поездка');
    }

    public function create(): void
    {
        $this->requireHousehold('poezdki');
        Csrf::guard();
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('trip/form', ['trip' => $data, 'errors' => $errors], 'Новая поездка');
            return;
        }
        $id = $this->trips->create(
            Auth::id(), $data['origin'], $data['destination'], $data['trip_date'],
            $data['trip_time'], $data['seats_total'], $data['note'], date('Y-m-d H:i:s')
        );
        Flash::set('success', 'Поездка опубликована.');
        header('Location: /poselenie/poezdki/' . $id);
        CatalogAnnounce::trip(                                  // уходит после ответа
            $id, $data['origin'], $data['destination'], $data['trip_date'], $data['trip_time'], $data['seats_total']
        );
    }

    public function mine(): void
    {
        $this->requireHousehold('poezdki');
        $me = Auth::id();
        $myTrips = $this->trips->listByDriver($me);
        View::render('trip/mine', [
            'trips'      => $myTrips,
            'incoming'   => $this->bookings->listIncoming($me, ['requested', 'confirmed']),
            'bookings'   => $this->bookings->listByPassenger($me),
            'deliveryRequests' => Sections::isEnabled('dostavka')
                ? (new DeliveryRepository())->listForTripDriver($me, ['requested'], date('Y-m-d')) : [],
        ], 'Мои поездки');
    }

    public function markDone(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        if ($trip['status'] !== 'active') { $this->alreadyClosed(); return; }
        // Без транзакции: при сбое между шагами неотвеченная заявка останется
        // у заказчика в «Моих доставках» как «ждёт водителя» — он может её
        // отменить и попросить заново. Риск мал, отдельной обработки нет.
        $this->trips->setStatus((int) $trip['id'], 'done');
        // Взятые водителем заявки остаются за ним; не отвеченные — на доску.
        $released = (new DeliveryRepository())->releaseTripRequests((int) $trip['id'], ['requested']);
        Flash::set('success', 'Поездка отмечена состоявшейся.');
        header('Location: /poselenie/poezdki/moi');
        $this->notifyReleased($released, DeliveryNotify::DONE_TRIP_SUBJECT, DeliveryNotify::doneTripLines(...));
    }

    public function cancelTrip(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        if ($trip['status'] !== 'active') { $this->alreadyClosed(); return; }
        // Сначала отменяем: к неактивной поездке новые просьбы не создаются, потом переводим старые.
        // Без транзакции: при сбое между шагами поездка уже отменена, а заявки
        // заказчик сам переведёт на доску или отменит — риск мал.
        $this->trips->setStatus((int) $trip['id'], 'cancelled');
        $released = (new DeliveryRepository())->releaseTripRequests((int) $trip['id']);
        Flash::set('info', 'Поездка отменена.');
        header('Location: /poselenie/poezdki/moi');
        $this->notifyReleased($released, DeliveryNotify::TRIP_CANCELLED_SUBJECT, DeliveryNotify::tripCancelledLines(...));
    }

    public function delete(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        if ($trip['status'] !== 'active') {
            // Закрытая поездка: просьбы к ней уже решены (взятые остаются за исполнителем,
            // FK ON DELETE SET NULL лишь отвяжет их от поездки) — просто удаляем.
            $this->trips->delete((int) $trip['id']); // брони каскадом
            Flash::set('info', 'Поездка удалена.');
            header('Location: /poselenie/poezdki/moi');
            return;
        }
        // Без транзакции: при сбое после отмены поездка останется отменённой и видна
        // водителю — удалить можно повторно; заявки не теряются — риск мал.
        $this->trips->setStatus((int) $trip['id'], 'cancelled');   // новые просьбы к ней больше не создаются
        $released = (new DeliveryRepository())->releaseTripRequests((int) $trip['id']); // до удаления: FK обнулил бы trip_id
        $this->trips->delete((int) $trip['id']); // брони каскадом
        Flash::set('info', 'Поездка удалена.');
        header('Location: /poselenie/poezdki/moi');
        $this->notifyReleased($released, DeliveryNotify::TRIP_CANCELLED_SUBJECT, DeliveryNotify::tripCancelledLines(...));
    }

    // --- helpers ---

    /**
     * Заказчикам просьб, которые из-за отмены (или завершения) поездки ушли на доску.
     * @param array<int,array<string,mixed>> $released
     * @param \Closure(array<string,mixed>):array<int,string> $lines тексты DeliveryNotify::*Lines
     */
    private function notifyReleased(array $released, string $subject, \Closure $lines): void
    {
        DeliveryNotify::sendMany($subject, array_map(
            static fn(array $d): array => [
                'family_id' => (int) $d['requester_id'],
                'email'     => $d['req_email'] ?? null,
                'lines'     => $lines($d),
            ],
            $released
        ), 'Доска: ', '/poselenie/dostavka');
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function validate(): array
    {
        $origin = trim($_POST['origin'] ?? '');
        $dest   = trim($_POST['destination'] ?? '');
        $date   = trim($_POST['trip_date'] ?? '');
        $time   = trim($_POST['trip_time'] ?? '');
        $seats  = (int) ($_POST['seats_total'] ?? 0);
        $note   = trim($_POST['note'] ?? '');
        $errors = [];

        if (!Validator::length($origin, 2, 160)) { $errors['origin'] = 'Откуда: 2–160 символов.'; }
        if (!Validator::length($dest, 2, 160)) { $errors['destination'] = 'Куда: 2–160 символов.'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors['trip_date'] = 'Укажите дату поездки.'; }
        elseif ($date < date('Y-m-d')) { $errors['trip_date'] = 'Дата не может быть в прошлом.'; }
        if ($seats < 1 || $seats > self::MAX_SEATS) { $errors['seats_total'] = 'Мест: от 1 до ' . self::MAX_SEATS . '.'; }

        return [[
            'origin' => $origin,
            'destination' => $dest,
            'trip_date' => $date,
            'trip_time' => $time !== '' ? mb_substr($time, 0, 40) : 'по договорённости',
            'seats_total' => max(1, min(self::MAX_SEATS, $seats)),
            'note' => $note !== '' ? $note : null,
        ], $errors];
    }

    /** Поездка уже состоялась или отменена — повторное действие ничего не меняет. */
    private function alreadyClosed(): void
    {
        Flash::set('info', 'Поездка уже закрыта.');
        header('Location: /poselenie/poezdki/moi');
    }

    private function guard(): void
    {
        $this->requireHousehold('poezdki');
        Csrf::guard();
    }

    private function ownedOr404(int $id): array
    {
        $t = $this->trips->findById($id);
        if (!$t || (int) $t['driver_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Поездка не найдена');
            exit;
        }
        return $t;
    }
}
