-- Авто-логин жителя во внутренний портал через Telegram Mini App.
-- Накатывается ОДИН раз:
--   mysql skazkray_residents < config/telegram-migration.sql
-- Аккаунт семьи привязывается к telegram_id; членство в группе жителей
-- (проверяется getChatMember) заменяет одобрение редактором — такие аккаунты
-- создаются сразу active. У аккаунтов, заведённых по email/паролю, telegram_id
-- остаётся NULL. Email у Telegram-аккаунта синтетический (tg<id>@telegram.local),
-- пароль — случайный неиспользуемый (вход только через Telegram).
ALTER TABLE families
    ADD COLUMN telegram_id BIGINT NULL UNIQUE AFTER email;
