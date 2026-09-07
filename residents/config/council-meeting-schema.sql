-- Схема «Ближайшее собрание совета» (редактируется через форму на /sovet).
-- Накатывается ОДИН раз вручную в ту же БД, что раздел жителей/совета:
--   mysql skazkray_residents < config/council-meeting-schema.sql
--
-- Раньше данные о встрече жили в коде (CouncilData::nextMeeting()). Теперь —
-- одна строка (id=1) в council_meeting, которую правит текущий Дежурный
-- председатель или администратор совета. Кто сейчас дежурный — определяется
-- флагом is_duty_chair в council_members (роль переходящая, назначается
-- админом по внешнему графику дежурств; один дежурный за раз).

CREATE TABLE IF NOT EXISTS council_meeting (
    id             TINYINT UNSIGNED PRIMARY KEY,       -- всегда 1 (одна ближайшая встреча)
    meeting_date   VARCHAR(160) NOT NULL DEFAULT '',   -- свободный текст: «7 сентября 2026, 18:00–20:00»
    place          VARCHAR(200) NOT NULL DEFAULT '',
    duty_chair     VARCHAR(160) NOT NULL DEFAULT '',   -- отображаемое имя дежурного председателя
    duty_secretary VARCHAR(160) NOT NULL DEFAULT '',
    agenda         MEDIUMTEXT   NULL,                  -- повестка: по одному пункту на строку
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Начальное значение = то, что сейчас зашито в CouncilData::nextMeeting().
INSERT INTO council_meeting (id, meeting_date, place, duty_chair, duty_secretary, agenda)
VALUES (1, '7 сентября 2026, 18:00–20:00', 'Сказочный Терем, 1-й этаж',
        'Наталья Нецветова', 'Александр Бобков', 'В процессе формирования')
ON DUPLICATE KEY UPDATE id = id;   -- не перетирать уже отредактированную строку

-- Признак «сейчас дежурит» на аккаунте члена совета (MariaDB поддерживает IF NOT EXISTS).
ALTER TABLE council_members ADD COLUMN IF NOT EXISTS is_duty_chair TINYINT(1) NOT NULL DEFAULT 0;
