-- Хранить message_id запроса на одобрение расходов, отправленного казначею.
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/council-task-expense-msgid.sql
--
-- Зачем: у ботов нет способа удалить «последнее» сообщение — нужен точный
-- message_id. Сохраняем chat_id + message_id запроса «Одобрить/Отклонить»,
-- чтобы после решения или отмены (задачу вернули в работу / удалили / убрали
-- расход) бот мог переписать это сообщение и убрать кнопки.
-- Обе колонки NULL, когда висящего запроса нет.

ALTER TABLE council_tasks
    ADD COLUMN expense_msg_chat_id VARCHAR(32) NULL AFTER expense_status,
    ADD COLUMN expense_msg_id      INT         NULL AFTER expense_msg_chat_id;
