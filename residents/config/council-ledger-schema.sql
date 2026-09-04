-- Схема модуля «Бюджет Общего дома» (учёт прихода/расхода совета).
-- Накатывается ОДИН раз вручную в ту же БД, что раздел жителей/совета:
--   mysql skazkray_residents < config/council-ledger-schema.sql
-- Статьи не удаляются физически при наличии операций — только архивируются (is_active=0).

CREATE TABLE council_ledger_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(16)  NOT NULL,                  -- income | expense
    name       VARCHAR(160) NOT NULL,
    position   INT          NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ledger_cat_kind (kind, is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_ledger_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        VARCHAR(16)   NOT NULL,                 -- income | expense (дублирует kind статьи)
    category_id INT UNSIGNED  NOT NULL,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    entry_date  DATE          NOT NULL,
    note        VARCHAR(300)  NOT NULL DEFAULT '',
    author      VARCHAR(160)  NOT NULL DEFAULT '',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_category FOREIGN KEY (category_id)
        REFERENCES council_ledger_categories(id),
    INDEX idx_ledger_date (entry_date),
    INDEX idx_ledger_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Стартовый справочник статей (админ совета правит через интерфейс).
INSERT INTO council_ledger_categories (kind, name, position) VALUES
('income', 'Из Фонда общего дома', 0),
('income', 'Коммерческая аренда',  1),
('income', 'Школа',                2),
('expense','Дороги и въезд',       0),
('expense','Электрика',            1),
('expense','Вывоз мусора',         2),
('expense','Праздники',            3),
('expense','Инвентарь',            4);
