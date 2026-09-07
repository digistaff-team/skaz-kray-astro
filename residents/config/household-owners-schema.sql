-- Совместное владение поместьем: несколько аккаунтов (Telegram/email) как
-- совладельцы ОДНОГО поместья — например, муж и жена, каждый со своим Telegram.
--
-- households.family_id остаётся «первичным» владельцем (первый, кто привязал) —
-- на нём завязаны проверки занятости (isClaimable/listForClaimView) и приватность
-- справочника «Наши соседи». household_owners — ПОЛНЫЙ список аккаунтов с доступом
-- к разделу «Моё поместье» (правка жителей/авто/питомцев/названия).
--
-- Инвариант: один аккаунт — одно поместье (UNIQUE family_id). Присоединение к
-- занятому поместью (второй член семьи) подтверждается фамилией, как и первичная
-- привязка. Первичный владелец всегда присутствует и в household_owners.
--
-- Накат ОДИН раз в БД skazkray_residents:
--   mysql skazkray_residents < config/household-owners-schema.sql

CREATE TABLE household_owners (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    family_id    INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_family (family_id),                    -- один аккаунт — одно поместье
    CONSTRAINT fk_owner_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_owner_family    FOREIGN KEY (family_id)    REFERENCES families(id)   ON DELETE CASCADE,
    INDEX idx_owners_household (household_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Бэкфилл: текущие привязки households.family_id → строки совладения (первичные владельцы).
INSERT INTO household_owners (household_id, family_id, created_at)
SELECT id, family_id, NOW() FROM households WHERE family_id IS NOT NULL;
