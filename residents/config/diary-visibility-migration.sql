-- 3-уровневая видимость записи дневника (private | residents | public).
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/diary-visibility-migration.sql
-- is_public держим синхронно (=1 только для public), чтобы внешняя лента /dnevniki-pomestiy не менялась.
ALTER TABLE diary_entries ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'residents' AFTER is_public;
UPDATE diary_entries SET visibility = CASE WHEN is_public = 1 THEN 'public' ELSE 'residents' END;
