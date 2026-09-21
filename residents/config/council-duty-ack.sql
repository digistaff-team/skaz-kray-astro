-- Подтверждение дежурства: новый Дежурный председатель жмёт кнопку «Дежурство принял»
-- под сообщением бота (ротация в понедельник 21:00) — фиксируем момент нажатия.
-- Сбрасывается при каждой ротации, поэтому в карточке встречи видно именно то,
-- принял ли дежурство ТЕКУЩИЙ дежурный.
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/council-duty-ack.sql
ALTER TABLE council_meeting
    ADD COLUMN IF NOT EXISTS duty_ack_at DATETIME NULL AFTER rotation_index;
