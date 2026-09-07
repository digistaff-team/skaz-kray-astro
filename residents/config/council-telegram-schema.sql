-- Вход членов совета через Telegram Mini App (@SkazKray_bot) + привязка по фамилии.
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзеру нельзя DDL):
--   mysql skazkray_residents < config/council-telegram-schema.sql
-- Затем сид ростера: php8.3 bin/council-roster-seed.php
--
-- Модель: заранее заведён список членов совета (surname + name, telegram_id=NULL).
-- Член открывает Mini App, вводит фамилию → его telegram_id привязывается к
-- строке ростера, он получает роль «Член Совета». Фамилии вне списка не пускаются.
-- Роль «Дежурный председатель» = флаг is_duty_chair (переходящая, один за раз).

ALTER TABLE council_members
    ADD COLUMN IF NOT EXISTS telegram_id BIGINT NULL UNIQUE AFTER email,
    ADD COLUMN IF NOT EXISTS surname VARCHAR(120) NOT NULL DEFAULT '' AFTER name;

CREATE INDEX IF NOT EXISTS idx_council_members_surname ON council_members (surname);
