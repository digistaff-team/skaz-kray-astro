-- Питомцы семьи (раздел «Наше поместье»). Отдельные записи: добавить/править/удалить.
-- Накатывается ОДИН раз в БД skazkray_residents:
--   mysql skazkray_residents < config/household-pets-schema.sql

CREATE TABLE household_pets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    name         VARCHAR(120) NOT NULL,                     -- кличка
    kind         VARCHAR(60)  NOT NULL DEFAULT '',          -- вид: кошка, собака, …
    note         VARCHAR(255) NOT NULL DEFAULT '',          -- примечание (порода, окрас и т.п.)
    sort         INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pet_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    INDEX idx_pets_household (household_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
