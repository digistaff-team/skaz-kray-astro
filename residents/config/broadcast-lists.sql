-- Сохранённые списки адресатов массовой рассылки в Telegram (BroadcastController):
-- админ может составить произвольный список семей, дать ему название и рассылать
-- по нему, не отмечая адресатов заново при каждом письме.
--
-- Список — именованный набор поместий (families). Членство намеренно не ссылается
-- на Telegram: привязка бота меняется, а список остаётся. На момент рассылки
-- отбираются только активные семьи с telegram_id (BroadcastController::resolveRecipients),
-- остальные молча пропускаются.
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.
--
-- Строка списка — ЛИБО поместье (family_id > 0, chat_id IS NULL),
-- ЛИБО чат-группа в Telegram (chat_id < 0, family_id = 0, см. broadcast-group-chats.sql).

CREATE TABLE IF NOT EXISTS broadcast_lists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcast_list_members (
    list_id   INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    added_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, family_id),
    INDEX idx_blm_family (family_id),
    CONSTRAINT fk_blm_list FOREIGN KEY (list_id)
        REFERENCES broadcast_lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;