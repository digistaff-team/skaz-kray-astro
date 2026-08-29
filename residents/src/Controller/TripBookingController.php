<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Config, Mailer};
use SkazResidents\Repository\{TripRepository, TripBookingRepository};

/**
 * Брони мест в совместных поездках:
 *  - пассажир бронирует место (request) и может отменить бронь (cancel);
 *  - водитель подтверждает (confirm: места списываются) или отклоняет (decline).
 * Места списываются ПРИ ПОДТВЕРЖДЕНИИ (как в эталоне), а не при заявке;
 * при отмене подтверждённой брони места возвращаются. Письма fail-open.
 */
final class TripBookingController
{
    private const MAX_SEATS = 8;

    public function __construct(
        private TripRepository $trips = new TripRepository(),
        private TripBookingRepository $bookings = new TripBookingRepository()
    ) {}

    public function book(array $params): void
    {
        $this->guard();
        $tripId = (int) $params['id'];
        $trip = $this->trips->findWithDriver($tripId);
        if (!$trip) { http_response_code(404); View::render('public/notfound', [], 'Поездка не найдена'); return; }

        $me = Auth::id();
        if ((int) $trip['driver_id'] === $me) {
            Flash::set('error', 'Это ваша поездка.');
            header('Location: /poselenie/poezdki/' . $tripId); return;
        }
        if ($trip['status'] !== 'active' || (int) $trip['seats_free'] <= 0 || $trip['trip_date'] < date('Y-m-d')) {
            Flash::set('error', 'Поездка недоступна для брони.');
            header('Location: /poselenie/poezdki/' . $tripId); return;
        }
        if ($this->bookings->activeForTripAndPassenger($tripId, $me) !== null) {
            Flash::set('error', 'Вы уже бронировали место в этой поездке.');
            header('Location: /poselenie/poezdki/' . $tripId); return;
        }

        $seats = (int) ($_POST['seats'] ?? 1);
        $seats = max(1, min(self::MAX_SEATS, (int) $trip['seats_free'], $seats));
        $message = trim($_POST['message'] ?? '');

        $this->bookings->create($tripId, $me, $seats, $message !== '' ? mb_substr($message, 0, 500) : null, date('Y-m-d H:i:s'));

        $this->mail($trip['driver_email'],
            'Бронь места в поездке «' . $trip['origin'] . ' → ' . $trip['destination'] . '» — Сказочный Край',
            "Здравствуйте!\n\nЖитель «" . Auth::name() . "» просит {$seats} место(а) в вашей поездке {$trip['origin']} → {$trip['destination']} ({$trip['trip_date']}, {$trip['trip_time']})."
            . ($message !== '' ? "\nСообщение: {$message}" : '')
            . "\n\nПодтвердить или отклонить бронь можно в разделе «Мои поездки»:\n" . Config::get('base_url') . '/poselenie/poezdki/moi'
        );
        Flash::set('success', 'Бронь отправлена водителю. Он получит уведомление.');
        header('Location: /poselenie/poezdki/' . $tripId);
    }

    public function cancel(array $params): void
    {
        $this->guard();
        $booking = $this->bookings->findById((int) $params['id']);
        if ($booking && (int) $booking['passenger_id'] === Auth::id()
            && in_array($booking['status'], ['requested', 'confirmed'], true)) {
            // Возврат мест, если бронь была подтверждена.
            if ($booking['status'] === 'confirmed') {
                $this->trips->adjustSeats((int) $booking['trip_id'], (int) $booking['seats']);
            }
            $this->bookings->setStatus((int) $booking['id'], 'cancelled');
            Flash::set('info', 'Бронь отменена.');
        }
        header('Location: /poselenie/poezdki/moi');
    }

    public function confirm(array $params): void
    {
        $this->guard();
        $b = $this->bookings->findDetailed((int) $params['id']);
        if (!$this->driverOf($b)) { return; }
        if ($b['status'] !== 'requested') {
            Flash::set('error', 'Бронь уже обработана.');
            header('Location: /poselenie/poezdki/moi'); return;
        }
        if ((int) $b['trip_seats_free'] < (int) $b['seats']) {
            $this->bookings->setStatus((int) $b['id'], 'declined', date('Y-m-d H:i:s'));
            Flash::set('error', 'Недостаточно свободных мест — бронь отклонена.');
            header('Location: /poselenie/poezdki/moi'); return;
        }
        $this->bookings->setStatus((int) $b['id'], 'confirmed', date('Y-m-d H:i:s'));
        $this->trips->adjustSeats((int) $b['trip_id'], -(int) $b['seats']);
        $this->mail($b['passenger_email'],
            'Поездка подтверждена — Сказочный Край',
            "Здравствуйте!\n\nВодитель подтвердил вашу бронь ({$b['seats']} место(а)) в поездке {$b['origin']} → {$b['destination']} ({$b['trip_date']}, {$b['trip_time']}).\nКонтакт водителя: {$b['driver_email']} ({$b['driver_name']})."
        );
        Flash::set('success', 'Бронь подтверждена.');
        header('Location: /poselenie/poezdki/moi');
    }

    public function decline(array $params): void
    {
        $this->guard();
        $b = $this->bookings->findDetailed((int) $params['id']);
        if (!$this->driverOf($b)) { return; }
        if ($b['status'] !== 'requested') {
            header('Location: /poselenie/poezdki/moi'); return;
        }
        $this->bookings->setStatus((int) $b['id'], 'declined', date('Y-m-d H:i:s'));
        $this->mail($b['passenger_email'],
            'Бронь в поездке отклонена — Сказочный Край',
            "Здравствуйте!\n\nК сожалению, водитель отклонил вашу бронь в поездке {$b['origin']} → {$b['destination']} ({$b['trip_date']})."
        );
        Flash::set('info', 'Бронь отклонена.');
        header('Location: /poselenie/poezdki/moi');
    }

    // --- helpers ---

    private function guard(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    /** Проверяет, что текущий житель — водитель поездки этой брони. */
    private function driverOf(?array $booking): bool
    {
        if (!$booking) { http_response_code(404); View::render('public/notfound', [], 'Бронь не найдена'); return false; }
        if ((int) $booking['driver_id'] !== Auth::id()) { http_response_code(403); exit('Доступ запрещён.'); }
        return true;
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try { Mailer::send($to, $subject, $body); }
        catch (\Throwable $e) { error_log('trip booking mail failed: ' . $e->getMessage()); }
    }
}
