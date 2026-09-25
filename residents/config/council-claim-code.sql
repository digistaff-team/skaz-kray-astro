-- Привязка Telegram к записи ростера совета — только по одноразовому коду от
-- администратора (/sovet/upravlenie → «выдать код»). Одной фамилии мало:
-- её знает любой, кто видел состав совета.
--
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзеру нельзя DDL), ДО деплоя кода:
--   mysql skazkray_residents < config/council-claim-code.sql

ALTER TABLE council_members
    ADD COLUMN IF NOT EXISTS claim_code_hash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS claim_code_expires DATETIME NULL;

CREATE INDEX IF NOT EXISTS idx_council_members_claim_code ON council_members (claim_code_hash);
