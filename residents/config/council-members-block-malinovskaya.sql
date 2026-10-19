-- Анастасия Малиновская вышла из состава ПС (см. council-duty-rotation-drop-malinovskaya.sql):
-- блокируем её аккаунт в админке совета. 'blocked' — штатное значение колонки
-- (active|blocked, council-schema.sql): SessionGuard запрещает вход и разлогинивает
-- уже вошедших, claimTelegramByCode не привязывает Telegram по коду, получатели
-- рассылки о встречах фильтруются по status='active'. Сид ростера
-- (bin/council-roster-seed.php) её строку пропускает и статус не перезаписывает —
-- блокировка не откатится при повторном сиде.
-- Идемпотентно (status <> 'blocked') — повторный накат после восстановления бэкапа
-- ничего не меняет. Совпадение проверяется по id И полному имени: id=13 не должен
-- прийтись на другую строку (name = полное «Имя Фамилия», фамилия — в колонке surname).
UPDATE council_members
   SET status = 'blocked'
 WHERE id = 13
   AND name = 'Анастасия Малиновская'
   AND surname = 'Малиновская'
   AND status <> 'blocked';
