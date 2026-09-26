-- Доставка в разделе «Поездки»: жители просят соседей привезти покупку
-- («Купить») или забрать оплаченный заказ («Забрать»). Просьба к конкретной
-- поездке (trip_id задан, водитель берёт или отказывается) или заявка на общую
-- доску (trip_id NULL, берёт первый откликнувшийся).
-- Спека: docs/superpowers/specs/2026-09-26-dostavka-design.md.
--
-- Статусы: requested (к поездке, ждёт водителя) | open (на доске) | accepted |
-- delivered | settled | declined (водитель отказался) | cancelled.
--
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1).

CREATE TABLE IF NOT EXISTS deliveries (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id  INT UNSIGNED  NOT NULL,                  -- кто просит
    carrier_id    INT UNSIGNED  NULL,                      -- кто везёт; NULL, пока не взяли
    trip_id       INT UNSIGNED  NULL,                      -- поездка, если просьба к водителю
    kind          VARCHAR(8)    NOT NULL,                  -- buy | pickup
    what          TEXT          NOT NULL,                  -- что купить / забрать
    place         VARCHAR(160)  NOT NULL,                  -- где: магазин, пункт выдачи
    need_by       DATE          NULL,                      -- к какому дню нужно
    budget        DECIMAL(10,2) NULL,                      -- примерная сумма (buy)
    pickup_code   VARCHAR(200)  NULL,                      -- код/номер заказа (pickup), видит только исполнитель
    note          VARCHAR(500)  NULL,
    receipt_sum   DECIMAL(10,2) NULL,                      -- сумма по чеку (при «Привёз»)
    status        VARCHAR(16)   NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at   DATETIME      NULL,
    delivered_at  DATETIME      NULL,
    settled_at    DATETIME      NULL,
    CONSTRAINT fk_delivery_requester FOREIGN KEY (requester_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_carrier   FOREIGN KEY (carrier_id)   REFERENCES families(id) ON DELETE SET NULL,
    CONSTRAINT fk_delivery_trip      FOREIGN KEY (trip_id)      REFERENCES trips(id)    ON DELETE SET NULL,
    INDEX idx_deliveries_board (status, need_by),
    INDEX idx_deliveries_requester (requester_id),
    INDEX idx_deliveries_carrier (carrier_id),
    INDEX idx_deliveries_trip (trip_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Раздел появляется выключенным: включают на странице настроек разделов после
-- проверки на проде. IGNORE — если строку уже завели руками, не трогаем.
INSERT IGNORE INTO app_sections (section_key, enabled) VALUES ('dostavka', 0);
