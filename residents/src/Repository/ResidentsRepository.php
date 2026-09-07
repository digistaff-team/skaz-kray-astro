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
        // Признак привязки к Telegram-аккаунту (tg_id) и его @username берём из families:
        // ПДн жителей раскрываем ТОЛЬКО для поместий, которые семья сама привязала при
        // входе через Telegram. Непривязанные — серые статичные плашки без жителей.
        $households = $this->db->query(
            "SELECT h.*, f.telegram_id AS tg_id, f.telegram_username AS tg_username, f.name AS tg_name
             FROM households h
             LEFT JOIN families f ON f.id = h.family_id AND f.status = 'active'
             ORDER BY h.sort, h.id"
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
            $h['claimed'] = $h['tg_id'] !== null;   // привязан к Telegram-аккаунту
            // Жителей и авто отдаём только для привязанных поместий (иначе ПДн не
            // раскрываем — карточка показывается серой статичной плашкой).
            $h['people'] = $h['claimed'] ? ($byHousehold[(int) $h['id']] ?? []) : [];
            $h['cars']   = $h['claimed'] ? ($byCars[(int) $h['id']] ?? []) : [];
            // Имя главы (первый житель по sort) — для заголовка «Поместье {Фамилия}»;
            // берём независимо от привязки (в заголовок идёт только фамилия семьи).
            $h['head_name'] = $byHousehold[(int) $h['id']][0]['full_name'] ?? null;
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
