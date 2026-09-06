-- Видимость разделов приложения (плитки на главной /poselenie/app + пункты меню).
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/app-sections-schema.sql
-- Хранит ТОЛЬКО переопределения: строка есть → раздел явно выключен (enabled=0)
-- или явно включён (enabled=1); строки нет → раздел включён (значение по умолчанию).
-- Список ключей разделов задан в PHP (SkazResidents\Sections::LIST) — здесь только состояние.

CREATE TABLE app_sections (
    section_key VARCHAR(64) PRIMARY KEY,          -- ключ раздела: dnevniki|instrumenty|knigi|poezdki|byudzhet|yarmarka|sosedi
    enabled     TINYINT(1)  NOT NULL DEFAULT 1,   -- 1 — показывать, 0 — скрыть плитку и пункт меню
    updated_at  DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
