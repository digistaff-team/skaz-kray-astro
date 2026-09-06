<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Справочник жителей (households + residents). Только чтение — данные наполняются
 * импортом bin/import-residents.php. Виден лишь вошедшим жителям (гард в контроллере).
 */
final class ResidentsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Поместья в порядке таблицы, каждое с вложенным списком людей.
     * $q — необязательный поиск: остаётся поместье, если совпадение есть у любого
     * из его жителей (по имени, навыкам, общественной роли, городу, поляне, поместью).
     *
     * @return array<int,array<string,mixed>>
     */
    public function grouped(?string $q = null): array
    {
        $households = $this->db->query(
            'SELECT * FROM households ORDER BY sort, id'
        )->fetchAll();
        if (!$households) { return []; }

        $people = $this->db->query(
            'SELECT * FROM residents ORDER BY household_id, sort, id'
        )->fetchAll();

        $byHousehold = [];
        foreach ($people as $p) {
            $byHousehold[(int) $p['household_id']][] = $p;
        }

        // Автомобили поместья (таблица household_cars) — источник истины для показа авто.
        $cars = $this->db->query('SELECT * FROM household_cars ORDER BY sort, id')->fetchAll();
        $byCars = [];
        foreach ($cars as $c) { $byCars[(int) $c['household_id']][] = $c; }

        $needle = $q !== null ? trim($q) : '';
        $result = [];
        foreach ($households as $h) {
            $h['people'] = $byHousehold[(int) $h['id']] ?? [];
            $h['cars']   = $byCars[(int) $h['id']] ?? [];
            if ($needle !== '' && !$this->matches($h, $needle)) { continue; }
            $result[] = $h;
        }
        return $result;
    }

    /** Совпадает ли поместье с поисковым запросом (по себе или любому жителю). */
    private function matches(array $household, string $needle): bool
    {
        $hay = [$household['glade'], $household['estate_name']];
        foreach ($household['people'] as $p) {
            $hay[] = $p['full_name'];
            $hay[] = $p['skills'];
            $hay[] = $p['community_role'];
            $hay[] = $p['hometown'];
        }
        foreach ($hay as $field) {
            if ($field !== null && $field !== '' && mb_stripos((string) $field, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array{households:int,people:int} */
    public function stats(): array
    {
        return [
            'households' => (int) $this->db->query('SELECT COUNT(*) FROM households')->fetchColumn(),
            'people'     => (int) $this->db->query('SELECT COUNT(*) FROM residents')->fetchColumn(),
        ];
    }
}
