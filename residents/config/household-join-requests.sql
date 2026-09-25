-- Заявки на присоединение к УЖЕ занятому поместью. Раньше совладельцем
-- становились сразу, подтвердив фамилию (а новый вход MAX даже склеивался с
-- аккаунтом владельца), — но фамилии жителей видны в «Наших соседях», и так
-- можно было захватить чужое поместье. Теперь заявку подтверждает владелец.
--
-- Один аккаунт — одна заявка (UNIQUE family_id). Заявка исчезает, как только
-- аккаунт привязан к какому-либо поместью.
--
-- Накат ОДИН раз ВРУЧНУЮ от root, ДО деплоя кода:
--   mysql skazkray_residents < config/household-join-requests.sql

CREATE TABLE IF NOT EXISTS household_join_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    family_id    INT UNSIGNED NOT NULL,
    surname      VARCHAR(120) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_join_family (family_id),
    CONSTRAINT fk_join_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_join_family    FOREIGN KEY (family_id)    REFERENCES families(id)   ON DELETE CASCADE,
    INDEX idx_join_household (household_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
