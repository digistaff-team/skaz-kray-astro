-- Идемпотентность рассылки уведомления о встрече (bin/council-meeting-notify.php).
-- Накатывать вручную от root MySQL:
--   mysql skazkray_residents < config/council-meeting-notify.sql
-- notified_for = дата встречи, за которую уже разослали (чтобы не слать повторно).

ALTER TABLE council_meeting
    ADD COLUMN IF NOT EXISTS notified_for DATE NULL AFTER ends_at;
