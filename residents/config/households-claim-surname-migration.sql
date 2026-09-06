-- Фамилия, по которой аккаунт привязался к поместью (households.claim_surname).
-- Нужна, чтобы показать ссылку «Tg» на карточке именно того жителя, чья фамилия
-- использована при авторизации. Заполняется в ProfileController::claim().
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/households-claim-surname-migration.sql
ALTER TABLE households ADD COLUMN claim_surname VARCHAR(120) NULL;
