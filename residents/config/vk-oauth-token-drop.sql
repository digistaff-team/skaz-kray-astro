-- Отказ от OAuth-ветки VK ID: стену ВК-группы «Вече» читает сервисный ключ
-- доступа приложения (VK_VECHESK_SERVICE_TOKEN в config/.env), он статичный и
-- refresh не требует. Таблица vk_oauth_token больше не используется.
--
-- ВАЖНО: таблицу vk_group_members_cache (кеш подписчиков) НЕ трогаем — она
-- по-прежнему нужна cron-синхронизации, её создаёт vk-oauth-token.sql.
--
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.
-- DDL в MySQL не откатывается — откат только восстановлением из бэкапа.

DROP TABLE IF EXISTS vk_oauth_token;
