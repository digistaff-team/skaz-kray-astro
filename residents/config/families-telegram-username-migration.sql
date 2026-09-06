-- Telegram-username аккаунта жителя (для ссылки «Tg» на карточке поместья в справочнике).
-- Заполняется при входе через Telegram (TgAuthController), если у пользователя есть @username.
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/families-telegram-username-migration.sql
ALTER TABLE families ADD COLUMN telegram_username VARCHAR(64) NULL AFTER telegram_id;
