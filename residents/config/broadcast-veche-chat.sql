-- Чат-группа жителей (-1001580770653) — адресат ещё и списка «Участники группы
-- Вече»: сообщение по этому списку уходит в сам чат, а посты ВК-группы «Вече»
-- (bin/vk-vechesk-sync.php, config vk_vechesk.list_id) начинают приходить и туда.
-- Накатывается bin/migrate.php (deploy.sh с MIGRATE=1), идемпотентно.
--
-- Список намеренно НЕ создаём: он заведён вручную, а его id зафиксирован в
-- config vk_vechesk.list_id. На чистой БД списка нет — вставка остаётся no-op.

INSERT INTO broadcast_list_members (list_id, family_id, chat_id, chat_title)
    SELECT l.id, 0, -1001580770653, 'Чат Действуем вместе'
    FROM broadcast_lists l
    WHERE l.name = 'Участники группы Вече'
      AND NOT EXISTS (
          SELECT 1 FROM broadcast_list_members m
          WHERE m.list_id = l.id AND m.chat_id = -1001580770653
      );
