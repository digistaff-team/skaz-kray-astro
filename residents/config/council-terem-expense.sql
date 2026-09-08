-- Связка «затраты задач Совета → бюджет» (отчёт по содержанию Терема).
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/council-terem-expense.sql
--
-- Идея: сумма затрат вводится в задаче (council_tasks.spent) + статья расхода;
-- при сохранении задачи создаётся/обновляется расходная операция бюджета,
-- помеченная source_task_id. Источник истины — задача.

ALTER TABLE council_tasks
    ADD COLUMN expense_category_id INT UNSIGNED NULL AFTER spent,
    ADD CONSTRAINT fk_task_expense_cat FOREIGN KEY (expense_category_id)
        REFERENCES council_ledger_categories(id) ON DELETE SET NULL;

ALTER TABLE council_ledger_entries
    ADD COLUMN source_task_id INT UNSIGNED NULL AFTER author,
    ADD UNIQUE KEY uq_ledger_source_task (source_task_id),
    ADD CONSTRAINT fk_ledger_source_task FOREIGN KEY (source_task_id)
        REFERENCES council_tasks(id) ON DELETE CASCADE;
