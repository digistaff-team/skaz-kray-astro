-- Профиль поместья: автомобили семьи как отдельные записи (можно добавлять/удалять).
-- Накатывается ОДИН раз в БД skazkray_residents:
--   mysql skazkray_residents < config/household-profile-schema.sql
-- Перенос существующих машин из residents.car — отдельным INSERT (см. runbook/деплой):
--   INSERT INTO household_cars (household_id, title)
--     SELECT household_id, car FROM residents WHERE car <> '' GROUP BY household_id, car;
--
-- Машина привязана к поместью (household), а не к человеку: у семьи может быть
-- несколько авто; житель, вошедший под аккаунтом поместья, управляет ими в
-- разделе «Моё поместье». Правки применяются сразу (без модерации).

CREATE TABLE household_cars (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,                     -- «Рено Логан, белый» (марка/модель/цвет свободным текстом)
    plate        VARCHAR(40)  NOT NULL DEFAULT '',          -- госномер
    note         VARCHAR(255) NOT NULL DEFAULT '',          -- примечание
    sort         INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_car_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    INDEX idx_cars_household (household_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
