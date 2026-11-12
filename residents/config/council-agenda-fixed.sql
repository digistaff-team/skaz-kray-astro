-- Постоянные пункты повестки Совета: всегда первыми в списке.
-- Накатывать вручную от root:
--   mysql skazkray_residents < config/council-agenda-fixed.sql
-- is_fixed=1 — пункт нельзя удалить и переместить; при переносе встречи
-- (rollOver) он не удаляется, не помечается «с прошлой встречи» и его
-- отметка «обсуждено» сбрасывается — каждый раз пункт начинается заново.

ALTER TABLE council_agenda_items
    ADD COLUMN IF NOT EXISTS is_fixed TINYINT(1) NOT NULL DEFAULT 0 AFTER author;

-- Два постоянных пункта с отрицательным sort — всегда выше обычных (их sort >= 0).
INSERT INTO council_agenda_items (title, author, sort, is_fixed)
SELECT 'Изменения в составе Совета', '', -2, 1
WHERE NOT EXISTS (SELECT 1 FROM council_agenda_items WHERE is_fixed = 1 AND title = 'Изменения в составе Совета');

INSERT INTO council_agenda_items (title, author, sort, is_fixed)
SELECT 'Статус по текущим задачам', '', -1, 1
WHERE NOT EXISTS (SELECT 1 FROM council_agenda_items WHERE is_fixed = 1 AND title = 'Статус по текущим задачам');