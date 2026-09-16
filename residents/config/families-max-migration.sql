-- Авто-логин жителя во внутренний портал через мини-приложение MAX (аналог
-- telegram-migration.sql). Накатывается ОДИН раз ВРУЧНУЮ от root MySQL:
--   mysql skazkray_residents < config/families-max-migration.sql
--
-- Аккаунт семьи привязывается к max_user_id; членство в группе жителей в MAX
-- (проверяется Bot API MAX) заменяет одобрение редактором — такие аккаунты
-- создаются сразу active. Email у MAX-аккаунта синтетический (max<id>@max.local),
-- пароль — случайный неиспользуемый (вход только через MAX). max_user_id
-- независим от telegram_id: житель может входить и из Telegram, и из MAX.

ALTER TABLE families
    ADD COLUMN max_user_id BIGINT NULL UNIQUE AFTER telegram_id;
