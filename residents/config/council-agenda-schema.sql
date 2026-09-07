-- Совместная повестка встречи Совета: пункты добавляют сами члены совета.
-- Накатывать вручную от root:
--   mysql skazkray_residents < config/council-agenda-schema.sql
-- Пункты относятся к текущей (единственной) встрече. При авто-переносе
-- (advance) обсуждённые удаляются, необсуждённые переходят на следующую встречу.

CREATE TABLE IF NOT EXISTS council_agenda_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(500) NOT NULL,
    author     VARCHAR(160) NOT NULL DEFAULT '',       -- кто предложил (имя члена совета)
    discussed  TINYINT(1)   NOT NULL DEFAULT 0,         -- обсуждено на встрече
    sort       INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agenda_sort (sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
