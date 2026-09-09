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
        // Признак привязки к Telegram-аккаунту (tg_id) берём из families: ПДн жителей
        // раскрываем ТОЛЬКО для поместий, которые семья сама привязала при входе через
        // Telegram. Непривязанные — серые статичные плашки без жителей.
        $households = $this->db->query(
            "SELECT h.*, f.telegram_id AS tg_id
             FROM households h
             LEFT JOIN families f ON f.id = h.family_id AND f.status = 'active'
             ORDER BY h.sort, h.id"
        )->fetchAll();
        if (!$households) { return []; }

        // Подключённые аккаунты каждого поместья (для показа «Tg» рядом с жителем).
        $accounts = $this->accountsByHousehold();

        $people = $this->db->query(
            'SELECT * FROM residents ORDER BY household_id, sort, id'
        )->fetchAll();
        $byHousehold = [];
        foreach ($people as $p) {
            $byHousehold[(int) $p['household_id']][] = $p;
        }

        // Автомобили и питомцы поместья. К каждому цепляем фото (полиморфная images)
        // одним запросом на тип, чтобы в справочнике показать миниатюры с раскрытием
        // на полный размер.
        $cars = $this->db->query('SELECT * FROM household_cars ORDER BY sort, id')->fetchAll();
        $carImages = $this->imagesFor('car', array_map(static fn($c) => (int) $c['id'], $cars));
        $byCars = [];
        foreach ($cars as $c) {
            $c['images'] = $carImages[(int) $c['id']] ?? [];
            $byCars[(int) $c['household_id']][] = $c;
        }

        $pets = $this->db->query('SELECT * FROM household_pets ORDER BY sort, id')->fetchAll();
        $petImages = $this->imagesFor('pet', array_map(static fn($p) => (int) $p['id'], $pets));
        $byPets = [];
        foreach ($pets as $p) {
            $p['images'] = $petImages[(int) $p['id']] ?? [];
            $byPets[(int) $p['household_id']][] = $p;
        }

        $needle = $q !== null ? trim($q) : '';
        $result = [];
        foreach ($households as $h) {
            $h['claimed'] = $h['tg_id'] !== null;   // привязан к Telegram-аккаунту
            // Жителей и авто отдаём только для привязанных поместий (иначе ПДн не
            // раскрываем — карточка показывается серой статичной плашкой).
            $h['people'] = $h['claimed'] ? ($byHousehold[(int) $h['id']] ?? []) : [];
            $h['cars']   = $h['claimed'] ? ($byCars[(int) $h['id']] ?? []) : [];
            $h['pets']   = $h['claimed'] ? ($byPets[(int) $h['id']] ?? []) : [];
            // Подключённые аккаунты (Telegram) — только для привязанных поместий.
            $h['accounts'] = $h['claimed'] ? ($accounts[(int) $h['id']] ?? []) : [];
            // Имя главы (первый житель по sort) — для заголовка «Поместье {Фамилия}»;
            // берём независимо от привязки (в заголовок идёт только фамилия семьи).
            $h['head_name'] = $byHousehold[(int) $h['id']][0]['full_name'] ?? null;
            if ($needle !== '' && !$this->matches($h, $needle)) { continue; }
            $result[] = $h;
        }
        return $result;
    }

    /**
     * Подключённые аккаунты поместий с Telegram-ссылкой — основной владелец
     * (households.family_id) и совладельцы (household_owners). Только активные и
     * только с публичным @username (без него ссылки t.me нет). UNION страхует от
     * легаси-поместий, где основной не попал в household_owners; dedup по fid.
     * @return array<int,array<int,array{tg_username:string,tg_name:string}>> hid => [аккаунты]
     */
    private function accountsByHousehold(): array
    {
        $rows = $this->db->query(
            "SELECT hid, telegram_username AS tg_username, tg_name FROM (
                SELECT h.id AS hid, f.id AS fid, f.telegram_username, f.name AS tg_name
                FROM households h
                JOIN families f ON f.id = h.family_id
                WHERE f.status = 'active' AND f.telegram_id IS NOT NULL
                  AND f.telegram_username IS NOT NULL AND f.telegram_username <> ''
                UNION
                SELECT o.household_id AS hid, f.id AS fid, f.telegram_username, f.name AS tg_name
                FROM household_owners o
                JOIN families f ON f.id = o.family_id
                WHERE f.status = 'active' AND f.telegram_id IS NOT NULL
                  AND f.telegram_username IS NOT NULL AND f.telegram_username <> ''
            ) t
            ORDER BY hid, fid"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['hid']][] = [
                'tg_username' => (string) $r['tg_username'],
                'tg_name'     => (string) $r['tg_name'],
            ];
        }
        return $out;
    }

    /**
     * Фото (полиморфная таблица images) для набора владельцев одного типа — одним
     * запросом, чтобы не плодить N+1 при построении справочника.
     * @param array<int,int> $ownerIds
     * @return array<int,array<int,array<string,mixed>>> owner_id => [images]
     */
    private function imagesFor(string $ownerType, array $ownerIds): array
    {
        if (!$ownerIds) { return []; }
        $in = implode(',', array_fill(0, count($ownerIds), '?'));
        $st = $this->db->prepare(
            "SELECT * FROM images WHERE owner_type = ? AND owner_id IN ($in) ORDER BY sort, id"
        );
        $st->execute([$ownerType, ...array_map('intval', $ownerIds)]);
        $out = [];
        foreach ($st->fetchAll() as $img) { $out[(int) $img['owner_id']][] = $img; }
        return $out;
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
