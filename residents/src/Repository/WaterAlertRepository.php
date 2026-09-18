<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Состояние оповещений о подходе воды к мосту (water_alert_state) — ровно одна
 * строка с id = 1. По ней SkazResidents\Service\WaterAlert решает, писать ли в
 * группу: сообщение уходит на ухудшении обстановки, на отбое и, пока держится
 * тревога, не чаще раза в несколько часов.
 *
 * status и level_cm — последнее известное состояние, обновляются на каждом
 * замере. notified_at — время последнего отправленного сообщения, NULL пока в
 * группу не писали.
 */
final class WaterAlertRepository
{
    private const ROW_ID = 1;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** Последнее отправленное состояние или null, если ещё ни разу не писали. */
    public function state(): ?array
    {
        $st = $this->db->prepare('SELECT * FROM water_alert_state WHERE id = ?');
        $st->execute([self::ROW_ID]);
        return $st->fetch() ?: null;
    }

    /**
     * Запомнить состояние. $notifiedAt передаётся, только когда сообщение ушло;
     * иначе прежнее время отправки сохраняется. Наличие строки проверяем отдельным
     * запросом (как в SectionSettingsRepository): rowCount у UPDATE в MariaDB
     * равен нулю и когда строка есть, но значения те же.
     */
    public function remember(string $status, float $levelCm, ?string $notifiedAt = null): void
    {
        $prev = $this->state();
        if ($prev !== null) {
            $stamp = $notifiedAt ?? ($prev['notified_at'] ?? null);
            $st = $this->db->prepare('UPDATE water_alert_state SET status = ?, level_cm = ?, notified_at = ? WHERE id = ?');
            $st->execute([$status, $levelCm, $stamp, self::ROW_ID]);
            return;
        }
        $ins = $this->db->prepare('INSERT INTO water_alert_state (id, status, level_cm, notified_at) VALUES (?, ?, ?, ?)');
        $ins->execute([self::ROW_ID, $status, $levelCm, $notifiedAt]);
    }
}
