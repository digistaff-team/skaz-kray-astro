-- Ключи доступа VK ID для пересылки постов ВК-группы «Вече» в Telegram
-- (bin/vk-vechesk-sync.php). Чтение стены группы (wall.get) доступно только
-- user-токену: групповой ключ VK к методам стены не допускает вообще.
--
-- User-токен живёт 1 час, а refresh_token ротируется при каждом обновлении —
-- поэтому храним их в БД, а не в config/.env (править .env каждый час нельзя,
-- deploy его не перезаписывает, но и обновлять руками нереально).
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.
--
-- Одна строка (id = 1) — одна связка токенов на весь раздел.

CREATE TABLE IF NOT EXISTS vk_oauth_token (
    id            TINYINT      NOT NULL PRIMARY KEY,
    access_token  VARCHAR(512) NOT NULL,
    refresh_token VARCHAR(512) NOT NULL,
    expires_at    DATETIME     NOT NULL,               -- когда access_token истекает
    updated_at    DATETIME     NOT NULL,
    last_error    VARCHAR(255) NULL                    -- ответ VK (для диагностики)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кеш списка подписчиков группы: состав меняется редко, а groups.getMembers
-- стоит один вызов API. При лимите 10 000 вызовов в месяц (без верификации
-- бизнес-профиля, правила VK от 19.08.2026) обновляем его раз в сутки, а не
-- на каждом прогоне cron каждые 5 минут.
CREATE TABLE IF NOT EXISTS vk_group_members_cache (
    id         TINYINT   NOT NULL PRIMARY KEY,   -- всегда 1
    group_id   BIGINT    NOT NULL,
    payload    MEDIUMTEXT NOT NULL,              -- JSON: список подписчиков как отдал VK
    fetched_at DATETIME  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
