<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Брони мест в совместных поездках. Жизненный цикл:
 * requested → confirmed → (места списаны у поездки) / requested → declined,
 * либо requested|confirmed → cancelled (при отмене подтверждённой места возвращаются).
 */
final class TripBookingRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $tripId, int $passengerId, int $seats, ?string $message, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO trip_bookings (trip_id, passenger_id, seats, status, message, created_at)
             VALUES (?, ?, ?, \'requested\', ?, ?)'
        );
        $st->execute([$tripId, $passengerId, $seats, $message, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM trip_bookings WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Бронь с данными поездки, водителя и пассажира. */
    public function findDetailed(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT b.*, t.origin, t.destination, t.trip_date, t.trip_time,
                    t.driver_id AS driver_id, t.seats_free AS trip_seats_free,
                    d.name AS driver_name, d.email AS driver_email,
                    p.name AS passenger_name, p.email AS passenger_email
             FROM trip_bookings b
             JOIN trips t     ON t.id = b.trip_id
             JOIN families d  ON d.id = t.driver_id
             JOIN families p  ON p.id = b.passenger_id
             WHERE b.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Активная (requested/confirmed) бронь пассажира на конкретную поездку, если есть. */
    public function activeForTripAndPassenger(int $tripId, int $passengerId): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM trip_bookings
             WHERE trip_id = ? AND passenger_id = ? AND status IN ('requested','confirmed')
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$tripId, $passengerId]);
        return $st->fetch() ?: null;
    }

    public function setStatus(int $id, string $status, ?string $decidedAt = null): void
    {
        if ($decidedAt !== null) {
            $st = $this->db->prepare('UPDATE trip_bookings SET status = ?, decided_at = ? WHERE id = ?');
            $st->execute([$status, $decidedAt, $id]);
        } else {
            $st = $this->db->prepare('UPDATE trip_bookings SET status = ? WHERE id = ?');
            $st->execute([$status, $id]);
        }
    }

    /** Брони на конкретную поездку (для водителя), с именами пассажиров. @return array<int,array<string,mixed>> */
    public function listForTrip(int $tripId): array
    {
        $st = $this->db->prepare(
            'SELECT b.*, p.name AS passenger_name, p.email AS passenger_email
             FROM trip_bookings b JOIN families p ON p.id = b.passenger_id
             WHERE b.trip_id = ? ORDER BY b.created_at ASC'
        );
        $st->execute([$tripId]);
        return $st->fetchAll();
    }

    /** Входящие брони по всем поездкам водителя (для «Мои поездки»). @return array<int,array<string,mixed>> */
    public function listIncoming(int $driverId, array $statuses = []): array
    {
        $sql = 'SELECT b.*, t.origin, t.destination, t.trip_date, t.trip_time, p.name AS passenger_name
                FROM trip_bookings b
                JOIN trips t    ON t.id = b.trip_id
                JOIN families p ON p.id = b.passenger_id
                WHERE t.driver_id = ?';
        $args = [$driverId];
        if ($statuses) {
            $sql .= ' AND b.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($args, ...$statuses);
        }
        $sql .= ' ORDER BY b.created_at DESC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Брони, где семья — пассажир. @return array<int,array<string,mixed>> */
    public function listByPassenger(int $passengerId): array
    {
        $st = $this->db->prepare(
            'SELECT b.*, t.origin, t.destination, t.trip_date, t.trip_time, t.status AS trip_status, d.name AS driver_name
             FROM trip_bookings b
             JOIN trips t    ON t.id = b.trip_id
             JOIN families d ON d.id = t.driver_id
             WHERE b.passenger_id = ?
             ORDER BY b.created_at DESC'
        );
        $st->execute([$passengerId]);
        return $st->fetchAll();
    }

    /** Число подтверждённых бронирований поездки (для подсчёта занятых мест). */
    public function confirmedSeats(int $tripId): int
    {
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(seats),0) FROM trip_bookings WHERE trip_id = ? AND status = 'confirmed'"
        );
        $st->execute([$tripId]);
        return (int) $st->fetchColumn();
    }
}
