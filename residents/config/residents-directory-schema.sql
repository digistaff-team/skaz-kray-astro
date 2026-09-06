-- Схема справочника жителей поселения — раздел «Соседи».
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/residents-directory-schema.sql
-- Импорт данных из гугл-таблицы — bin/import-residents.php (dry-run по умолчанию).
--
-- Модель: households (поместье/двор на участке) → residents (люди в нём).
-- Это СПРАВОЧНИК (ПДн жителей), отдельный от логин-таблицы families. Одно
-- поместье МОЖЕТ быть привязано к логин-аккаунту (households.family_id), но
-- большинство людей (дети, жители без email) аккаунта не имеют. Раздел виден
-- только вошедшим жителям (Auth::requireLogin) — публичной выдачи ПДн нет.

CREATE TABLE households (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    glade        VARCHAR(120) NOT NULL DEFAULT '',            -- поляна, напр. «(1) Обережная»
    plot         VARCHAR(40)  NOT NULL DEFAULT '',            -- № участка (строка: «1», «-», «?»)
    estate_name  VARCHAR(160) NOT NULL DEFAULT '',            -- название поместья, напр. «АгудариЯ»
    status_raw   VARCHAR(40)  NOT NULL DEFAULT '',            -- сырой «Статус» из таблицы (1..5, «-», «?»)
    joined_text  VARCHAR(120) NOT NULL DEFAULT '',            -- дата вступления в СК, свободный текст
    family_id    INT UNSIGNED NULL,                           -- привязка логин-аккаунта (если создан)
    sort         INT          NOT NULL DEFAULT 0,             -- порядок как в таблице
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_household_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE SET NULL,
    INDEX idx_households_glade (glade, plot),
    INDEX idx_households_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE residents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id   INT UNSIGNED NOT NULL,
    full_name      VARCHAR(200) NOT NULL,                     -- «Фамилия Имя»
    birth_raw      VARCHAR(60)  NOT NULL DEFAULT '',          -- дата рождения как в таблице («08.05.1972», «Май 2013»)
    birth_date     DATE         NULL,                         -- распарсенная дата, где удалось
    phone          VARCHAR(60)  NOT NULL DEFAULT '',
    vk             VARCHAR(200) NOT NULL DEFAULT '',          -- ссылка/логин VK
    skills         MEDIUMTEXT   NULL,                         -- вид деятельности, навыки, таланты
    community_role MEDIUMTEXT   NULL,                         -- общественная деятельность в поселении
    moved_text     VARCHAR(120) NOT NULL DEFAULT '',          -- дата переезда (для постоянно живущих)
    residence      VARCHAR(200) NOT NULL DEFAULT '',          -- место проживания (для ещё не переехавших)
    car            VARCHAR(200) NOT NULL DEFAULT '',          -- марка и цвет машины
    hometown       VARCHAR(160) NOT NULL DEFAULT '',          -- родной город
    email          VARCHAR(255) NOT NULL DEFAULT '',
    questionnaire  VARCHAR(255) NOT NULL DEFAULT '',          -- ссылка на анкету
    comment        MEDIUMTEXT   NULL,                         -- комментарии
    updated_text   VARCHAR(60)  NOT NULL DEFAULT '',          -- «Дата последнего обновления информации» из таблицы
    sort           INT          NOT NULL DEFAULT 0,           -- порядок внутри поместья
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resident_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    INDEX idx_residents_household (household_id, sort),
    INDEX idx_residents_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
