-- История уровня воды в Шебше (иконка-капля на главной приложения).
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/water-level-history.sql
--
-- Источник — гидропост AllRivers, который скрапит приложение shebsh-water-level
-- (Vercel). Оттуда берём только текущий замер: bin/water-level-snapshot.php раз
-- в час пишет сюда строку, и историей владеем мы, а не чужой Vercel KV.
--
-- level_cm — сантиметры от условного нуля гидропоста (38.158 м БСВ), бывает
-- отрицательным. change_24h — изменение за сутки со знаком, как отдаёт источник.
-- measured_at — час замера (UTC, минуты обнулены): первичный ключ гасит дубли,
-- если cron сработает дважды или ручной вызов попадёт в тот же час.

CREATE TABLE IF NOT EXISTS water_level_history (
    measured_at DATETIME     NOT NULL,
    level_cm    DECIMAL(8,2) NOT NULL,
    change_24h  DECIMAL(8,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
