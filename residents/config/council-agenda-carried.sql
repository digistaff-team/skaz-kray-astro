-- Пометка «с прошлой встречи» для перенесённых пунктов повестки.
-- Накатывать вручную от root:
--   mysql skazkray_residents < config/council-agenda-carried.sql
-- При авто-переносе встречи необсуждённые пункты остаются и помечаются
-- carried_over=1; новые пункты, добавленные членами, — carried_over=0.

ALTER TABLE council_agenda_items
    ADD COLUMN IF NOT EXISTS carried_over TINYINT(1) NOT NULL DEFAULT 0 AFTER discussed;
