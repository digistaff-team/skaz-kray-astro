<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Заявки на доставку (раздел «Поездки»). Каждый переход статуса — один UPDATE
 * с условием на текущий статус: двое нажали «Возьму» одновременно — сработает
 * у одного, второй получит false. Спека: docs/superpowers/specs/2026-09-26-dostavka-design.md.
 */
final class DeliveryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $requesterId, ?int $tripId, string $kind, string $what, string $place,
                           ?string $needBy, ?string $budget, ?string $pickupCode, ?string $note, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO deliveries (requester_id, trip_id, kind, what, place, need_by, budget, pickup_code, note, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$requesterId, $tripId, $kind, $what, $place, $needBy, $budget, $pickupCode, $note,
            $tripId !== null ? 'requested' : 'open', $now]);
        return (int) $this->db->lastInsertId();
    }

    /** Заявка с именами и контактами сторон и данными поездки (если есть). */
    public function findDetailed(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT d.*,
                    r.name AS req_name, r.email AS req_email, r.telegram_username AS req_tg,
                    c.name AS car_name, c.email AS car_email, c.telegram_username AS car_tg,
                    t.origin, t.destination, t.trip_date, t.trip_time, t.status AS trip_status,
                    t.driver_id AS trip_driver_id,
                    dr.name AS driver_name, dr.email AS driver_email
             FROM deliveries d
             JOIN families r       ON r.id = d.requester_id
             LEFT JOIN families c  ON c.id = d.carrier_id
             LEFT JOIN trips t     ON t.id = d.trip_id
             LEFT JOIN families dr ON dr.id = t.driver_id
             WHERE d.id = ?'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Доска: открытые, срок не прошёл или не задан. Сначала со сроком — ближайшие
     * выше, потом без срока, внутри — кто раньше попросил.
     * @return array<int,array<string,mixed>>
     */
    public function listBoard(string $today, string $kind = ''): array
    {
        $sql = "SELECT d.*, r.name AS req_name FROM deliveries d JOIN families r ON r.id = d.requester_id
                WHERE d.status = 'open' AND (d.need_by IS NULL OR d.need_by >= ?)";
        $args = [$today];
        if ($kind === 'buy' || $kind === 'pickup') { $sql .= ' AND d.kind = ?'; $args[] = $kind; }
        $sql .= ' ORDER BY (d.need_by IS NULL) ASC, d.need_by ASC, d.created_at ASC, d.id ASC';
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /**
     * Просьбы жителя. Пустой $statuses — все просьбы без фильтра («Мои доставки»);
     * в отличие от listForTripDriver, где пустой список означает «ничего».
     *
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public function listByRequester(int $requesterId, array $statuses = []): array
    {
        $sql = 'SELECT d.*, c.name AS car_name FROM deliveries d LEFT JOIN families c ON c.id = d.carrier_id
                WHERE d.requester_id = ?';
        if ($statuses !== []) {
            $sql .= ' AND d.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        }
        $st = $this->db->prepare($sql . ' ORDER BY d.created_at DESC, d.id DESC');
        $st->execute([$requesterId, ...$statuses]);
        return $st->fetchAll();
    }

    /** Взятые жителем. @return array<int,array<string,mixed>> */
    public function listByCarrier(int $carrierId): array
    {
        $st = $this->db->prepare(
            "SELECT d.*, r.name AS req_name FROM deliveries d JOIN families r ON r.id = d.requester_id
             WHERE d.carrier_id = ? AND d.status IN ('accepted','delivered','settled')
             ORDER BY d.created_at DESC, d.id DESC"
        );
        $st->execute([$carrierId]);
        return $st->fetchAll();
    }

    /**
     * Просьбы к поездкам водителя (для «Мои поездки», «Мои доставки», «Мои дела»).
     * $today задан — только к ещё актуальным поездкам (active и не в прошлом):
     * на прошедшую или отменённую поездку «Возьму» уже не имеет смысла.
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public function listForTripDriver(int $driverId, array $statuses, ?string $today = null): array
    {
        if ($statuses === []) {
            // MariaDB отказывает на `IN ()` синтаксической ошибкой (SQLite её молча
            // пропускает и просто ничего не находит) — не даём SQL решать за нас.
            return [];
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $args = [$driverId, ...$statuses];
        $live = '';
        if ($today !== null) {
            $live = " AND t.status = 'active' AND t.trip_date >= ?";
            $args[] = $today;
        }
        $st = $this->db->prepare(
            "SELECT d.*, r.name AS req_name, t.origin, t.destination, t.trip_date, t.status AS trip_status
             FROM deliveries d
             JOIN trips t    ON t.id = d.trip_id
             JOIN families r ON r.id = d.requester_id
             WHERE t.driver_id = ? AND d.status IN ($in)$live
             ORDER BY d.created_at ASC, d.id ASC"
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    /**
     * «Возьму»: из $fromStatus ('open' — доска, 'requested' — водитель).
     * Правило «при fromStatus 'requested' исполнителем может стать только водитель
     * этой поездки» — на стороне DeliveryPolicy в контроллере, здесь не проверяется.
     */
    public function take(int $id, int $carrierId, string $fromStatus, string $now): bool
    {
        return $this->exec(
            "UPDATE deliveries SET status = 'accepted', carrier_id = ?, accepted_at = ? WHERE id = ? AND status = ?",
            [$carrierId, $now, $id, $fromStatus]
        );
    }

    /** Водитель: «Не смогу» на просьбу к поездке. */
    public function decline(int $id): bool
    {
        return $this->exec("UPDATE deliveries SET status = 'declined' WHERE id = ? AND status = 'requested'", [$id]);
    }

    /**
     * Обратно на доску без исполнителя и поездки: исполнитель отказался («Не смогу»
     * после «Возьму») или заказчик снял исполнителя. Проверка carrier_id — не
     * оптимизация, а страховка от гонки: устаревший/повторный сабмит формы не
     * снимет уже другого, более нового исполнителя, который мог взять заявку
     * между открытием формы и её отправкой.
     */
    public function releaseCarrier(int $id, int $carrierId): bool
    {
        return $this->exec(
            "UPDATE deliveries SET status = 'open', carrier_id = NULL, trip_id = NULL, accepted_at = NULL
             WHERE id = ? AND status = 'accepted' AND carrier_id = ?",
            [$id, $carrierId]
        );
    }

    /**
     * «Выложить на доску»: заказчик — после отказа водителя (declined) или когда
     * просьба (requested) застряла у неактивной/прошедшей поездки. Уже без поездки.
     * Можно ли — решает DeliveryPolicy в контроллере, здесь только статус.
     */
    public function toBoard(int $id): bool
    {
        return $this->exec(
            "UPDATE deliveries SET status = 'open', trip_id = NULL WHERE id = ? AND status IN ('declined','requested')",
            [$id]
        );
    }

    public function deliver(int $id, int $carrierId, ?string $receiptSum, string $now): bool
    {
        return $this->exec(
            "UPDATE deliveries SET status = 'delivered', receipt_sum = ?, delivered_at = ? WHERE id = ? AND status = 'accepted' AND carrier_id = ?",
            [$receiptSum, $now, $id, $carrierId]
        );
    }

    public function settle(int $id, string $now): bool
    {
        return $this->exec("UPDATE deliveries SET status = 'settled', settled_at = ? WHERE id = ? AND status = 'delivered'", [$now, $id]);
    }

    public function cancel(int $id): bool
    {
        return $this->exec(
            "UPDATE deliveries SET status = 'cancelled' WHERE id = ? AND status IN ('requested','open','accepted')",
            [$id]
        );
    }

    /**
     * Поездку отменили или удаляют: незавершённые просьбы к ней — на доску.
     * Вызывающий должен сначала перевести саму поездку в нерабочий статус
     * (например, 'cancelled') — иначе после нашего SELECT, но до UPDATE, к ней
     * успеет прийти новая просьба и её здесь не будет.
     * Переводим построчно (не одним UPDATE по списку id): так возвращаем только
     * заявки, которые действительно переехали — на случай, если чья-то заявка
     * поменяла статус между SELECT и переводом (гонка), уведомление уйдёт точно
     * по факту, а не по снимку до перевода.
     * Звать ДО удаления поездки: ON DELETE SET NULL обнулил бы trip_id, оставив статус.
     *
     * $statuses — какие заявки переводить: при отмене — и ждущие, и взятые;
     * когда поездка состоялась — только ждущие ответа ('requested').
     *
     * @param array<int,string> $statuses
     * @return array<int,array<string,mixed>>
     */
    public function releaseTripRequests(int $tripId, array $statuses = ['requested', 'accepted']): array
    {
        if ($statuses === []) {
            return [];                       // `IN ()` MariaDB не примет
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $st = $this->db->prepare(
            "SELECT d.id, d.requester_id, d.kind, d.what, d.place, d.need_by, r.email AS req_email
             FROM deliveries d JOIN families r ON r.id = d.requester_id
             WHERE d.trip_id = ? AND d.status IN ($in)"
        );
        $st->execute([$tripId, ...$statuses]);
        $rows = $st->fetchAll();

        $moved = [];
        foreach ($rows as $row) {
            $ok = $this->exec(
                "UPDATE deliveries SET status = 'open', carrier_id = NULL, trip_id = NULL, accepted_at = NULL
                 WHERE id = ? AND trip_id = ? AND status IN ($in)",
                [$row['id'], $tripId, ...$statuses]
            );
            if ($ok) { $moved[] = $row; }
        }
        return $moved;
    }

    /** @param array<int,mixed> $args */
    private function exec(string $sql, array $args): bool
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->rowCount() > 0;
    }
}
