# Уведомлять модераторов о новом — план

**Спека:** `docs/superpowers/specs/2026-09-24-moderatoram-o-novom-design.md`.
Выполняется сразу в этой сессии (задача маленькая), по TDD, тесты — на сервере
`abconsult` в `/root/ledger-test-dela`.

1. **`FamilyRepository::listModerators()`** — тест в `FamilyRepositoryTest`:
   активные `editor` и `admin` попадают, `resident` и неактивный редактор — нет.
2. **`Service/ModerationNotify`** — тест `ModerationNotifyTest`: `entersQueue()`
   (новое `public` — да; правка `pending` — нет; правка `published`/`rejected` —
   да; `residents`/`private` — нет), `recipients()` (без автора), `lines()`
   (текст; без строки «От:» при пустом авторе). Затем `queued()`: после ответа,
   бот + письмо каждому получателю, сбой одного — в лог.
3. **Вызовы**: `ProductController::create/update`, `DiaryController::create/update`,
   `AuthController::register` — после `header('Location')`; у нового объявления —
   внутри того же блока после загрузки фото.
4. Полный прогон, линт, коммит; пуш и деплой — по разрешению.
