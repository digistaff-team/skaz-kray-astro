-- Добавляет флаг «опубликовать на внешнем сайте» к записям дневника.
-- Накатывается ОДИН раз в БД skazkray_residents:
--   mysql skazkray_residents < config/diary-public-migration.sql
-- По умолчанию 0 (не опубликовано вовне) — существующие записи остаются
-- видны только жителям на внутреннем портале, пока семья явно не отметит галочку.
ALTER TABLE diary_entries
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
