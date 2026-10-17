-- Журнал исходящих Telegram-сообщений от @SkazKray_bot: chat_id + message_id,
-- чтобы позже переписать (editMessageText) или удалить сообщение — у ботов нет
-- метода «удалить последнее», нужен точный message_id.
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.
--
-- context: broadcast (рассылка) | family (личное семье) | group (группа жителей)
--         | council (Совет: дежурство, задачи, одобрение расходов) | water (сигнал о воде)
-- kind:   send (отправлено) | edit (переписано этим же ботом)
-- family_id — только для личных сообщений семье (NULL, если чат не привязан к семье).
-- text — обрезанный фрагмент (500 символов), для поиска/идентификации сообщения.

CREATE TABLE IF NOT EXISTS telegram_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id    VARCHAR(32)   NOT NULL,
    message_id INT           NOT NULL,
    family_id  INT UNSIGNED  NULL,
    context    VARCHAR(16)   NOT NULL,
    kind       VARCHAR(8)    NOT NULL DEFAULT 'send',
    text       VARCHAR(500)  NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tg_msg_chat (chat_id, message_id),
    INDEX idx_tg_msg_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;