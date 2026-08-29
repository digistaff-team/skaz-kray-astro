-- Схема сервиса совместных поездок (попутки) — раздел жителей.
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/rides-schema.sql
-- Модель (по эталону Ride_Share_Bot, адаптирована под веб): водитель-семья
-- публикует поездку A→B на дату/время с числом мест; пассажир-семья
-- бронирует место; при ПОДТВЕРЖДЕНИИ водителем свободные места уменьшаются.

CREATE TABLE trips (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id    INT UNSIGNED NOT NULL,                      -- водитель
    origin       VARCHAR(160) NOT NULL,                      -- откуда
    destination  VARCHAR(160) NOT NULL,                      -- куда
    trip_date    DATE         NOT NULL,                      -- дата поездки
    trip_time    VARCHAR(40)  NULL,                          -- время (строка: «09:00» / «по договорённости»)
    seats_total  INT          NOT NULL DEFAULT 1,            -- мест изначально
    seats_free   INT          NOT NULL DEFAULT 1,            -- свободно сейчас (уменьшается при подтверждении)
    note         MEDIUMTEXT   NULL,                          -- комментарий (условия, «за бензин» и т.п.)
    status       VARCHAR(16)  NOT NULL DEFAULT 'active',     -- active|done|cancelled
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_driver FOREIGN KEY (driver_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_trips_status_date (status, trip_date),
    INDEX idx_trips_driver (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_bookings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id       INT UNSIGNED NOT NULL,
    passenger_id  INT UNSIGNED NOT NULL,                     -- пассажир
    seats         INT          NOT NULL DEFAULT 1,           -- сколько мест
    status        VARCHAR(16)  NOT NULL DEFAULT 'requested', -- requested|confirmed|declined|cancelled
    message       VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at    DATETIME     NULL,                         -- когда водитель подтвердил/отклонил
    CONSTRAINT fk_booking_trip      FOREIGN KEY (trip_id)      REFERENCES trips(id)    ON DELETE CASCADE,
    CONSTRAINT fk_booking_passenger FOREIGN KEY (passenger_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_bookings_trip (trip_id, status),
    INDEX idx_bookings_passenger (passenger_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
