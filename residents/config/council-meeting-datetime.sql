-- Структурные дата/время ближайшего собрания (для datetime-local пикеров).
-- Накатывать вручную от root MySQL:
--   mysql skazkray_residents < config/council-meeting-datetime.sql
-- Человекочитаемая meeting_date собирается из этих полей в коде.

ALTER TABLE council_meeting
    ADD COLUMN IF NOT EXISTS starts_at DATETIME NULL AFTER meeting_date,
    ADD COLUMN IF NOT EXISTS ends_at   DATETIME NULL AFTER starts_at;

-- Засев текущей строки структурными значениями (было только meeting_date-текст
-- «7 сентября 2026, 18:00–20:00»).
UPDATE council_meeting
   SET starts_at = '2026-09-07 18:00:00', ends_at = '2026-09-07 20:00:00'
 WHERE id = 1 AND starts_at IS NULL;
