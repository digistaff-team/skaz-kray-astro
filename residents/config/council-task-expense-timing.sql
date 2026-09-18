-- Предоплата / постоплата по расходам задач Совета.
-- Накатывается ОДИН раз ВРУЧНУЮ от root MySQL (app-юзер не имеет прав DDL):
--   mysql skazkray_residents < config/council-task-expense-timing.sql
--
-- Идея: у задачи с ненулевой суммой указывается, когда нужны деньги.
--   post (по умолчанию) — возмещение расходов: запрос казначею уходит ПОСЛЕ выполнения;
--   pre                 — финансирование предоплаты: запрос уходит СРАЗУ после сохранения.
-- Дальше поток прежний: pending → approved | rejected (см. council-task-expense-approval.sql).

ALTER TABLE council_tasks
    ADD COLUMN expense_timing VARCHAR(8) NOT NULL DEFAULT 'post' AFTER expense_category_id;
