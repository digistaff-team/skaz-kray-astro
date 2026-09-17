-- Схема раздела «Закупки» (совместные оптовые закупки) — раздел жителей.
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/purchases-schema.sql
-- Модель: организатор-семья публикует закупку ОДНОГО товара с ценой за единицу
-- и целью по объёму; житель-семья записывается своим количеством. Собранный
-- объём и суммы НЕ хранятся счётчиками, а считаются по purchase_orders —
-- рассинхрон невозможен.

CREATE TABLE purchases (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizer_id   INT UNSIGNED   NOT NULL,                     -- кто ведёт закупку
    title          VARCHAR(200)   NOT NULL,                     -- что закупаем
    category       VARCHAR(80)    NULL,                         -- продукты | стройматериалы | одежда | …
    unit           VARCHAR(20)    NOT NULL DEFAULT 'шт',        -- единица: кг, шт, мешок, м³
    price_per_unit DECIMAL(10,2)  NULL,                         -- оптовая цена за единицу
    target_qty     DECIMAL(10,2)  NULL,                         -- сколько нужно набрать (ориентир)
    deadline       DATE           NULL,                         -- до какого числа собираем
    supplier       VARCHAR(200)   NULL,                         -- поставщик / ссылка на прайс
    pickup         VARCHAR(200)   NULL,                         -- где забирать
    note           MEDIUMTEXT     NULL,                         -- описание товара, условия
    status         VARCHAR(16)    NOT NULL DEFAULT 'collecting',-- collecting|ordered|arrived|done|cancelled
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_organizer FOREIGN KEY (organizer_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_purchases_status (status, deadline),
    INDEX idx_purchases_organizer (organizer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED  NOT NULL,
    family_id   INT UNSIGNED  NOT NULL,                         -- участник
    qty         DECIMAL(10,2) NOT NULL,                         -- сколько берёт
    note        VARCHAR(500)  NULL,                             -- пожелание участника
    paid_at     DATETIME      NULL,                             -- организатор отметил оплату
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_porder_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_porder_family   FOREIGN KEY (family_id)   REFERENCES families(id)  ON DELETE CASCADE,
    -- одна заявка на семью: повторная запись правит прежнюю, а не плодит строки
    UNIQUE KEY uq_porder_purchase_family (purchase_id, family_id),
    INDEX idx_porder_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
