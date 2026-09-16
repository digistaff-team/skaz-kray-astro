-- Вход членов совета через мини-приложение MAX (@SkazKray_bot в MAX) + привязка
-- по фамилии. Ставится ПОСЛЕ council-telegram-schema.sql.
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзеру нельзя DDL):
--   mysql skazkray_residents < config/council-max-schema.sql
--
-- max_user_id независим от telegram_id: один член совета может входить и через
-- Telegram Mini App, и через мини-приложение MAX (обе привязки к одной строке
-- ростера, но по своей фамилии-клейму для каждой платформы отдельно).

ALTER TABLE council_members
    ADD COLUMN IF NOT EXISTS max_user_id BIGINT NULL UNIQUE AFTER telegram_id;

CREATE INDEX IF NOT EXISTS idx_council_members_max ON council_members (max_user_id);
