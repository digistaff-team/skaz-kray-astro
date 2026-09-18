<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * История уровня воды в Шебше (water_level_history). Одна строка — один час,
 * ключ measured_at гасит повторы. Пишет только bin/water-level-snapshot.php
 * (cron раз в час) и показ панели, когда последний замер устарел.
 *
 * level_cm — сантиметры от нуля гидропоста, бывает отрицательным.
 */
final class WaterLevelRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Записать замер за его час. Повторный вызов в тот же час обновляет строку,
     * а не плодит новые (портируемо между MariaDB и SQLite — без ON DUPLICATE).
     * @param string $measuredAt 'Y-m-d H:i:s' UTC; минуты и секунды обнуляются
     */
    public function save(string $measuredAt, float $levelCm, float $change24h): void
    {
        $hour = substr($measuredAt, 0, 13) . ':00:00';
        $exists = $this->db->prepare('SELECT 1 FROM water_level_history WHERE measured_at = ?');
        $exists->execute([$hour]);
        if ($exists->fetchColumn() !== false) {
            $st = $this->db->prepare('UPDATE water_level_history SET level_cm = ?, change_24h = ? WHERE measured_at = ?');
            $st->execute([$levelCm, $change24h, $hour]);
            return;
        }
        $st = $this->db->prepare('INSERT INTO water_level_history (measured_at, level_cm, change_24h) VALUES (?, ?, ?)');
        $st->execute([$hour, $levelCm, $change24h]);
    }

    /** Есть ли уже замер за этот час ('Y-m-d H:i:s'). Нужен импортёру для отчёта. */
    public function has(string $measuredAt): bool
    {
        $hour = substr($measuredAt, 0, 13) . ':00:00';
        $st = $this->db->prepare('SELECT 1 FROM water_level_history WHERE measured_at = ?');
        $st->execute([$hour]);
        return $st->fetchColumn() !== false;
    }

    /** Последний замер или null, если история пуста. @return array<string,mixed>|null */
    public function latest(): ?array
    {
        $row = $this->db->query('SELECT * FROM water_level_history ORDER BY measured_at DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    /**
     * Точки для диаграммы: последний замер каждых суток за $days дней, по возрастанию.
     * Суточная свёртка делается в PHP — переносимо между MariaDB и SQLite.
     * @return array<int,array{date:string,level_cm:float,change_24h:float}>
     */
    public function dailyPoints(int $days, string $now): array
    {
        $from = date('Y-m-d H:i:s', strtotime($now) - $days * 86400);
        $st = $this->db->prepare('SELECT * FROM water_level_history WHERE measured_at >= ? ORDER BY measured_at ASC');
        $st->execute([$from]);

        $byDay = [];
        foreach ($st->fetchAll() as $row) {
            $day = substr((string) $row['measured_at'], 0, 10);
            $byDay[$day] = [
                'date'       => $day,
                'level_cm'   => (float) $row['level_cm'],
                'change_24h' => (float) $row['change_24h'],
            ];
        }
        return array_values($byDay);
    }
}
