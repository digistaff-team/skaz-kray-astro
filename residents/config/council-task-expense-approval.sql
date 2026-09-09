-- Одобрение расходов по задачам Совета казначеем (Сергей Шубин) через бота.
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/council-task-expense-approval.sql
--
-- Идея: задача с суммой (spent) и статьёй расхода (expense_category_id), отмеченная
-- «выполнена», уходит казначею в Telegram с кнопками «Одобрить»/«Отклонить».
-- Расход попадает в бюджет (council_ledger_entries) ТОЛЬКО после «Одобрить».
-- expense_status: none → pending → approved | rejected.

ALTER TABLE council_tasks
    ADD COLUMN expense_status VARCHAR(16) NOT NULL DEFAULT 'none' AFTER expense_category_id;

-- Уже попавшие в бюджет расходы задач считаем одобренными, чтобы после включения
-- гейта они не пропали из отчёта.
UPDATE council_tasks t
    JOIN council_ledger_entries e ON e.source_task_id = t.id
    SET t.expense_status = 'approved';
