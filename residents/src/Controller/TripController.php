<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View};
use SkazResidents\Repository\{TripRepository, TripBookingRepository};

/**
 * Совместные поездки (попутки) — раздел жителей. Доска предстоящих поездок
 * видна вошедшим жителям; водитель-семья публикует поездку, пассажиры бронируют.
 */
final class TripController
{
    private const MAX_SEATS = 8;

    public function __construct(
        private TripRepository $trips = new TripRepository(),
        private TripBookingRepository $bookings = new TripBookingRepository()
    ) {}

    public function board(): void
    {
        Auth::requireLogin();
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
        Auth::requireLogin();
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
        ], $trip['origin'] . ' → ' . $trip['destination']);
    }

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('trip/form', ['trip' => null, 'errors' => []], 'Новая поездка');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
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
    }

    public function mine(): void
    {
        Auth::requireLogin();
        $me = Auth::id();
        $myTrips = $this->trips->listByDriver($me);
        View::render('trip/mine', [
            'trips'      => $myTrips,
            'incoming'   => $this->bookings->listIncoming($me, ['requested', 'confirmed']),
            'bookings'   => $this->bookings->listByPassenger($me),
        ], 'Мои поездки');
    }

    public function markDone(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        $this->trips->setStatus((int) $trip['id'], 'done');
        Flash::set('success', 'Поездка отмечена состоявшейся.');
        header('Location: /poselenie/poezdki/moi');
    }

    public function cancelTrip(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        $this->trips->setStatus((int) $trip['id'], 'cancelled');
        Flash::set('info', 'Поездка отменена.');
        header('Location: /poselenie/poezdki/moi');
    }

    public function delete(array $params): void
    {
        $this->guard();
        $trip = $this->ownedOr404((int) $params['id']);
        $this->trips->delete((int) $trip['id']); // брони каскадом
        Flash::set('info', 'Поездка удалена.');
        header('Location: /poselenie/poezdki/moi');
    }

    // --- helpers ---

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

    private function guard(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
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
