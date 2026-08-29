<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Совместные поездки (попутки). Водитель-семья публикует поездку; свободные
 * места уменьшаются при подтверждении брони (TripBookingRepository).
 */
final class TripRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $driverId, string $origin, string $destination, string $date, ?string $time, int $seats, ?string $note, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO trips (driver_id, origin, destination, trip_date, trip_time, seats_total, seats_free, note, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
        );
        $st->execute([$driverId, $origin, $destination, $date, $time, $seats, $seats, $note, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM trips WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findWithDriver(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT t.*, f.name AS driver_name, f.email AS driver_email
             FROM trips t JOIN families f ON f.id = t.driver_id WHERE t.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE trips SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    /** Изменить число свободных мест на $delta (может быть отрицательным). Не уходит ниже 0. */
    public function adjustSeats(int $id, int $delta): void
    {
        $st = $this->db->prepare('UPDATE trips SET seats_free = MAX(0, seats_free + ?) WHERE id = ?');
        $st->execute([$delta, $id]);
    }

    /**
     * Доска предстоящих поездок: активные, с датой >= сегодня, есть свободные места.
     * $today — 'Y-m-d'. Фильтры: подстрока по маршруту, конкретная дата.
     * @return array<int,array<string,mixed>>
     */
    public function listUpcoming(string $today, string $search = '', string $date = ''): array
    {
        $sql = 'SELECT t.*, f.name AS driver_name
                FROM trips t JOIN families f ON f.id = t.driver_id
                WHERE t.status = \'active\' AND t.seats_free > 0 AND t.trip_date >= ?';
        $args = [$today];
        if ($search !== '') {
            $sql .= ' AND (t.origin LIKE ? OR t.destination LIKE ?)';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }
        if ($date !== '') {
            $sql .= ' AND t.trip_date = ?';
            $args[] = $date;
        }
        $sql .= ' ORDER BY t.trip_date ASC, t.trip_time ASC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Поездки, где семья — водитель. @return array<int,array<string,mixed>> */
    public function listByDriver(int $driverId): array
    {
        $st = $this->db->prepare('SELECT * FROM trips WHERE driver_id = ? ORDER BY trip_date DESC');
        $st->execute([$driverId]);
        return $st->fetchAll();
    }

    public function delete(int $id): void
    {
        // trip_bookings уходят каскадом FK.
        $st = $this->db->prepare('DELETE FROM trips WHERE id = ?');
        $st->execute([$id]);
    }
}
