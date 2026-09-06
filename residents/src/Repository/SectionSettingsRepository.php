<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Хранилище состояния «вкл/выкл» разделов приложения (таблица app_sections).
 * Key-value поверх ключей из SkazResidents\Sections::LIST. Хранятся только
 * переопределения: строки нет → раздел включён по умолчанию.
 */
final class SectionSettingsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * Ключи явно выключенных разделов.
     * @return array<int,string>
     */
    public function disabledKeys(): array
    {
        $rows = $this->db->query('SELECT section_key FROM app_sections WHERE enabled = 0')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /**
     * Включить/выключить раздел (upsert). Портируемо между MariaDB (прод) и
     * SQLite (тесты) — сначала проверяем наличие строки, потом UPDATE или INSERT
     * (без диалект-специфичного ON DUPLICATE/ON CONFLICT).
     */
    public function setEnabled(string $key, bool $enabled, string $now): void
    {
        $val = $enabled ? 1 : 0;
        $exists = $this->db->prepare('SELECT 1 FROM app_sections WHERE section_key = ?');
        $exists->execute([$key]);
        if ($exists->fetchColumn() !== false) {
            $st = $this->db->prepare('UPDATE app_sections SET enabled = ?, updated_at = ? WHERE section_key = ?');
            $st->execute([$val, $now, $key]);
        } else {
            $st = $this->db->prepare('INSERT INTO app_sections (section_key, enabled, updated_at) VALUES (?, ?, ?)');
            $st->execute([$key, $val, $now]);
        }
    }
}
