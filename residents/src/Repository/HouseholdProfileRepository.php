<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Данные раздела «Наше поместье»: поместье вошедшего аккаунта, его жители и авто.
 * Доступ строго к своему поместью (household.family_id = Auth::id()) — проверки
 * принадлежности member/car выполняет контроллер через *BelongsTo. Правки сразу.
 */
final class HouseholdProfileRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Поместье, к которому привязан аккаунт (первичный владелец ИЛИ совладелец),
     * или null. Резолвится через household_owners — один аккаунт держит одно поместье.
     */
    public function householdByFamily(int $familyId): ?array
    {
        $st = $this->db->prepare(
            'SELECT h.* FROM households h
             JOIN household_owners o ON o.household_id = h.id
             WHERE o.family_id = ? LIMIT 1'
        );
        $st->execute([$familyId]);
        return $st->fetch() ?: null;
    }

    /** Является ли аккаунт владельцем/совладельцем поместья. */
    public function isOwner(int $householdId, int $familyId): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM household_owners WHERE household_id = ? AND family_id = ?');
        $st->execute([$householdId, $familyId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Все аккаунты-совладельцы поместья (для показа в «Моём поместье»): имя,
     * @username для ссылки в Telegram, флаг первичного владельца. Первичный — первым.
     * @return array<int,array<string,mixed>>
     */
    public function owners(int $householdId): array
    {
        $st = $this->db->prepare(
            'SELECT o.family_id, o.created_at, f.name, f.telegram_username, f.email,
                    CASE WHEN h.family_id = o.family_id THEN 1 ELSE 0 END AS is_primary
             FROM household_owners o
             JOIN families f ON f.id = o.family_id
             JOIN households h ON h.id = o.household_id
             WHERE o.household_id = ?
             ORDER BY is_primary DESC, o.id ASC'
        );
        $st->execute([$householdId]);
        return $st->fetchAll();
    }

    public function householdById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM households WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function updateHouseholdEstate(int $id, string $estateName): void
    {
        $st = $this->db->prepare('UPDATE households SET estate_name = ? WHERE id = ?');
        $st->execute([$estateName, $id]);
    }

    /** Фамилия, по которой аккаунт привязался к поместью (для ссылки «Tg» у жителя). */
    public function setClaimSurname(int $id, string $surname): void
    {
        $st = $this->db->prepare('UPDATE households SET claim_surname = ? WHERE id = ?');
        $st->execute([$surname, $id]);
    }

    // ── Выбор/привязка своего поместья ──────────────────────────────────────
    /**
     * Поместья, доступные для привязки: без аккаунта или привязанные к неактивному
     * (placeholder) аккаунту. ПДн жителей НЕ раскрываются на этапе выбора — только
     * поляна/участок/название поместья.
     * @return array<int,array<string,mixed>>
     */
    public function listClaimable(): array
    {
        return $this->db->query(
            "SELECT h.* FROM households h
             LEFT JOIN families f ON f.id = h.family_id
             WHERE h.family_id IS NULL OR f.status <> 'active'
             ORDER BY h.sort, h.id"
        )->fetchAll();
    }

    /**
     * Все поместья для экрана выбора: показываем ВСЕ участки поляны (число фиксировано),
     * помечая занятые активным аккаунтом (occupied=1) и число жителей (для решения,
     * можно ли открыть участок — привязка возможна только к участку с жителями).
     * ПДн жителей не раскрываются — только поляна/участок/название/флаги.
     * @return array<int,array<string,mixed>>
     */
    public function listForClaimView(): array
    {
        return $this->db->query(
            "SELECT h.id, h.glade, h.plot, h.estate_name,
                    CASE WHEN h.family_id IS NOT NULL AND f.status = 'active' THEN 1 ELSE 0 END AS occupied,
                    (SELECT COUNT(*) FROM residents r WHERE r.household_id = h.id) AS member_count
             FROM households h
             LEFT JOIN families f ON f.id = h.family_id
             ORDER BY h.sort, h.id"
        )->fetchAll();
    }

    /**
     * Подтверждение принадлежности без раскрытия жителей: совпадает ли введённая
     * фамилия с фамилией (первое слово ФИО) кого-то из жителей поместья.
     */
    public function surnameMatchesHousehold(int $householdId, string $surname): bool
    {
        $needle = mb_strtolower(trim($surname));
        if ($needle === '') { return false; }
        $st = $this->db->prepare('SELECT full_name FROM residents WHERE household_id = ?');
        $st->execute([$householdId]);
        foreach ($st->fetchAll() as $r) {
            // Сверяем с КАЖДЫМ словом ФИО — порядок бывает «Фамилия Имя» и «Имя Фамилия».
            foreach (preg_split('/\s+/u', mb_strtolower(trim((string) $r['full_name']))) ?: [] as $tok) {
                if ($tok !== '' && $tok === $needle) { return true; }
            }
        }
        return false;
    }

    /** Можно ли привязать поместье к аккаунту (не привязано или привязка неактивна). */
    public function isClaimable(int $householdId): bool
    {
        $st = $this->db->prepare(
            "SELECT (h.family_id IS NULL OR f.status <> 'active') AS claimable
             FROM households h LEFT JOIN families f ON f.id = h.family_id WHERE h.id = ?"
        );
        $st->execute([$householdId]);
        $r = $st->fetch();
        return $r ? (bool) $r['claimable'] : false;
    }

    /**
     * Первичная привязка СВОБОДНОГО поместья к аккаунту (аккаунт становится
     * первичным владельцем). Один аккаунт — одно поместье: прежние привязки
     * аккаунта снимаются. Перепроверяет доступность в транзакции; возвращает
     * false, если поместье уже занято активным аккаунтом.
     */
    public function claim(int $householdId, int $familyId): bool
    {
        $this->db->beginTransaction();
        try {
            if (!$this->isClaimable($householdId)) { $this->db->rollBack(); return false; }
            $this->detachAccount($familyId);
            // Свободное поместье могло числиться за placeholder-аккаунтом импорта — чистим его совладельцев.
            $this->db->prepare('DELETE FROM household_owners WHERE household_id = ?')->execute([$householdId]);
            $this->db->prepare('UPDATE households SET family_id = ? WHERE id = ?')->execute([$familyId, $householdId]);
            $this->db->prepare('INSERT INTO household_owners (household_id, family_id) VALUES (?, ?)')->execute([$householdId, $familyId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Присоединяет аккаунт к УЖЕ занятому поместью как совладельца, НЕ меняя
     * первичного владельца (households.family_id). Один аккаунт — одно поместье:
     * прежние привязки аккаунта снимаются.
     */
    public function joinAsOwner(int $householdId, int $familyId): void
    {
        $this->db->beginTransaction();
        try {
            $this->detachAccount($familyId);
            $this->db->prepare('INSERT INTO household_owners (household_id, family_id) VALUES (?, ?)')->execute([$householdId, $familyId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Убирает совладельца из поместья. Если убрали первичного владельца —
     * назначает первичным следующего оставшегося совладельца (или снимает
     * привязку households.family_id, если владельцев не осталось).
     */
    public function removeOwner(int $householdId, int $familyId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM household_owners WHERE household_id = ? AND family_id = ?')->execute([$householdId, $familyId]);
            $h = $this->householdById($householdId);
            if ($h && (int) ($h['family_id'] ?? 0) === $familyId) {
                $st = $this->db->prepare('SELECT family_id FROM household_owners WHERE household_id = ? ORDER BY id ASC LIMIT 1');
                $st->execute([$householdId]);
                $next = $st->fetchColumn();
                $this->db->prepare('UPDATE households SET family_id = ? WHERE id = ?')
                    ->execute([$next === false ? null : $next, $householdId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Снимает все привязки аккаунта к поместьям (первичную и совладение). */
    private function detachAccount(int $familyId): void
    {
        $this->db->prepare('UPDATE households SET family_id = NULL WHERE family_id = ?')->execute([$familyId]);
        $this->db->prepare('DELETE FROM household_owners WHERE family_id = ?')->execute([$familyId]);
    }

    // ── Жители ──────────────────────────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function members(int $householdId): array
    {
        $st = $this->db->prepare('SELECT * FROM residents WHERE household_id = ? ORDER BY sort, id');
        $st->execute([$householdId]);
        return $st->fetchAll();
    }

    public function memberById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM residents WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,?string> $d */
    public function addMember(int $householdId, array $d, string $now): int
    {
        $sort = (int) $this->one('SELECT COALESCE(MAX(sort)+1,0) FROM residents WHERE household_id = ?', [$householdId]);
        $st = $this->db->prepare(
            'INSERT INTO residents
               (household_id, full_name, birth_raw, birth_date, phone, vk, skills, community_role,
                moved_text, residence, hometown, email, comment, updated_text, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $householdId, $d['full_name'], $d['birth_raw'], $d['birth_date'], $d['phone'], $d['vk'],
            $d['skills'], $d['community_role'], $d['moved_text'], $d['residence'], $d['hometown'],
            $d['email'], $d['comment'], $now, $sort,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,?string> $d */
    public function updateMember(int $id, array $d, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE residents SET
               full_name = ?, birth_raw = ?, birth_date = ?, phone = ?, vk = ?, skills = ?,
               community_role = ?, moved_text = ?, residence = ?, hometown = ?, email = ?,
               comment = ?, updated_text = ?
             WHERE id = ?'
        );
        $st->execute([
            $d['full_name'], $d['birth_raw'], $d['birth_date'], $d['phone'], $d['vk'], $d['skills'],
            $d['community_role'], $d['moved_text'], $d['residence'], $d['hometown'], $d['email'],
            $d['comment'], $now, $id,
        ]);
    }

    public function deleteMember(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM residents WHERE id = ?');
        $st->execute([$id]);
    }

    // ── Автомобили ──────────────────────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function cars(int $householdId): array
    {
        $st = $this->db->prepare('SELECT * FROM household_cars WHERE household_id = ? ORDER BY sort, id');
        $st->execute([$householdId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<int,array<string,mixed>>> households.id => [cars] */
    public function carsForHouseholds(array $householdIds): array
    {
        if (!$householdIds) { return []; }
        $in = implode(',', array_fill(0, count($householdIds), '?'));
        $st = $this->db->prepare("SELECT * FROM household_cars WHERE household_id IN ($in) ORDER BY sort, id");
        $st->execute(array_map('intval', $householdIds));
        $out = [];
        foreach ($st->fetchAll() as $c) { $out[(int) $c['household_id']][] = $c; }
        return $out;
    }

    public function carById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM household_cars WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function addCar(int $householdId, string $title, string $plate, string $note): int
    {
        $sort = (int) $this->one('SELECT COALESCE(MAX(sort)+1,0) FROM household_cars WHERE household_id = ?', [$householdId]);
        // created_at ставит БД (DEFAULT CURRENT_TIMESTAMP) — не передаём человекочитаемую дату.
        $st = $this->db->prepare(
            'INSERT INTO household_cars (household_id, title, plate, note, sort) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$householdId, $title, $plate, $note, $sort]);
        return (int) $this->db->lastInsertId();
    }

    public function updateCar(int $id, string $title, string $plate, string $note): void
    {
        $st = $this->db->prepare('UPDATE household_cars SET title = ?, plate = ?, note = ? WHERE id = ?');
        $st->execute([$title, $plate, $note, $id]);
    }

    public function deleteCar(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM household_cars WHERE id = ?');
        $st->execute([$id]);
    }

    // ── Питомцы ─────────────────────────────────────────────────────────────
    /** @return array<int,array<string,mixed>> */
    public function pets(int $householdId): array
    {
        $st = $this->db->prepare('SELECT * FROM household_pets WHERE household_id = ? ORDER BY sort, id');
        $st->execute([$householdId]);
        return $st->fetchAll();
    }

    public function petById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM household_pets WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function addPet(int $householdId, string $name, string $kind, string $note): int
    {
        $sort = (int) $this->one('SELECT COALESCE(MAX(sort)+1,0) FROM household_pets WHERE household_id = ?', [$householdId]);
        $st = $this->db->prepare(
            'INSERT INTO household_pets (household_id, name, kind, note, sort) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$householdId, $name, $kind, $note, $sort]);
        return (int) $this->db->lastInsertId();
    }

    public function updatePet(int $id, string $name, string $kind, string $note): void
    {
        $st = $this->db->prepare('UPDATE household_pets SET name = ?, kind = ?, note = ? WHERE id = ?');
        $st->execute([$name, $kind, $note, $id]);
    }

    public function deletePet(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM household_pets WHERE id = ?');
        $st->execute([$id]);
    }

    /** @param array<int,mixed> $params */
    private function one(string $sql, array $params): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
