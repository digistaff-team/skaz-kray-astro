-- Состояние оповещений о подходе воды к мосту (одна строка, id = 1).
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/water-alert-state.sql
--
-- Нужна, чтобы часовой cron (bin/water-level-snapshot.php) не писал в группу
-- каждый час: сообщение уходит при ухудшении обстановки (спокойно → внимание →
-- тревога), при отбое и, пока держится тревога, не чаще раза в несколько часов.
-- status повторяет SkazResidents\Service\WaterLevel::status(): calm | watch | alert.
--
-- Строка обновляется на КАЖДОМ замере (последнее известное состояние — по нему
-- ловится скачок шкалы источника), а notified_at — только когда сообщение ушло;
-- NULL значит «в группу ещё не писали».

CREATE TABLE IF NOT EXISTS water_alert_state (
    id          TINYINT UNSIGNED NOT NULL,
    status      VARCHAR(8)       NOT NULL,
    level_cm    DECIMAL(8,2)     NOT NULL,
    notified_at DATETIME         NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
