-- Рассылка в чаты-группы: в broadcast_list_members появляется тип адресата «чат»
-- (строка — ЛИБО поместье, ЛИБО чат: chat_id отрицательный, family_id = 0).
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.

ALTER TABLE broadcast_list_members
    ADD COLUMN chat_id BIGINT NULL DEFAULT NULL AFTER family_id,
    ADD COLUMN chat_title VARCHAR(160) NULL DEFAULT NULL AFTER chat_id;

-- Чат в списке — один раз (NULL-строки поместий UNIQUE не участвуют).
-- Если индекс уже есть (повторный накат после сбоя) — миграция безопасна.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'broadcast_list_members'
               AND index_name = 'uq_blm_chat');
SET @sql := IF(@idx = 0,
    'CREATE UNIQUE INDEX uq_blm_chat ON broadcast_list_members (list_id, chat_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Список адресатов «Чат Действуем вместе» с чатом-группой (адресат по умолчанию).
-- Идемпотентно: список заводится только если его ещё нет, чат в него дописывается
-- только если такого члена ещё нет (у broadcast_lists нет UNIQUE на name).
INSERT INTO broadcast_lists (name)
SELECT 'Чат Действуем вместе' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM broadcast_lists WHERE name = 'Чат Действуем вместе');

INSERT INTO broadcast_list_members (list_id, family_id, chat_id, chat_title)
    SELECT l.id, 0, -1001580770653, 'Чат Действуем вместе'
    FROM broadcast_lists l
    WHERE l.name = 'Чат Действуем вместе'
      AND NOT EXISTS (
          SELECT 1 FROM broadcast_list_members m
          WHERE m.list_id = l.id AND m.chat_id = -1001580770653
      );