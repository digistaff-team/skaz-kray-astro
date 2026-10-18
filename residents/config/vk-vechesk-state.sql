-- Состояние пересылки постов ВК-группы «Вече» (https://vk.ru/vechesk) в Telegram-
-- рассылку «Участники Вече» (bin/vk-vechesk-sync.php из cron). Одна строка, id = 1.
-- Накатывается ОДИН РАЗ ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/vk-vechesk-state.sql
--
-- Нужна, чтобы каждый прогон пересылал только посты НОВЕЕ последнего пересланного:
-- last_post_id — id записи стены (wall.post), уже доставленной подписчикам.
-- NULL — ещё ничего не пересылали (первый прогон только запомнит последний пост,
-- старые в рассылку не пойдут). updated_at — когда состояние менялось (для логов).

CREATE TABLE IF NOT EXISTS vk_vechesk_state (
    id           TINYINT UNSIGNED NOT NULL,
    last_post_id BIGINT UNSIGNED  NULL,
    updated_at   DATETIME         NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;