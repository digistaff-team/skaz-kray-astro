-- Единица измерения цены товара/услуги Ярмарки (шт., час, кг. и т.д.).
-- Пусто у всех старых товаров — цена там свободный текст и единица часто уже внутри него.
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/products-unit-migration.sql
ALTER TABLE products ADD COLUMN unit VARCHAR(20) NULL AFTER price;
