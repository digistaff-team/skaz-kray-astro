-- Видимость товара: residents («только соседи» — внутрипоселенческий рынок) | public («на сайте», раздел Ярмарка).
-- Накатывается вручную на прод-БД skazkray_residents (деплой схему не мигрирует):
--   mysql skazkray_residents < config/products-visibility-migration.sql
-- Существующие товары были рассчитаны на внешний сайт — переносим в public.
ALTER TABLE products ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public' AFTER contact;
UPDATE products SET visibility = 'public';
