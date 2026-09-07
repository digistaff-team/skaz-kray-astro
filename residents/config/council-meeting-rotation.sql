-- Указатель ротации Дежурного председателя (позиция в CouncilDutyRotation::ORDER).
-- Двигается на +1 только когда встреча состоялась (авто-перенос понедельник 23:59),
-- поэтому перенос встречи НЕ сдвигает очередь — тот же дежурный остаётся на
-- перенесённую встречу. Накатывать вручную от root:
--   mysql skazkray_residents < config/council-meeting-rotation.sql
-- Затем разово засеять текущую позицию (см. деплой): rotation_index = 10
-- (текущий дежурный Наталья, поз.10; следующая встреча → Ольга Жулидова, поз.0).

ALTER TABLE council_meeting
    ADD COLUMN IF NOT EXISTS rotation_index TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER notified_for;
