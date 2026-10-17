<?php
/**
 * Список подзадач задачи + форма добавления. Отдельный partial: рендерится и из
 * tasks.php, и из TaskController::subtasksHtml() — AJAX-добавление подтягивает
 * этот же HTML, не перезагружая страницу (задача остаётся раскрытой).
 *
 * Ожидаемые переменные: $t (задача с ключом 'subtasks'), $id (id задачи), $sort.
 */
use SkazResidents\Csrf;
use SkazResidents\View;
foreach ($t['subtasks'] as $s): $sid = (int) $s['id']; $done = (int) $s['done'] === 1; $stitle = (string) $s['title']; ?>
    <div class="sovet-sub<?= $done ? ' sovet-sub--done' : '' ?><?= $stitle === '' ? ' sovet-sub--blank' : '' ?>" id="sub-<?= $sid ?>">
        <form method="post" action="/sovet/podzadacha/<?= $sid ?>/pereklyuchit">
            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
            <button class="sovet-check" type="submit" title="Отметить"><?= $done ? '☑' : '☐' ?></button>
        </form>
        <form class="sovet-sub-rename" method="post" action="/sovet/podzadacha/<?= $sid ?>/pereimenovat">
            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
            <!-- Пустое название: крестика справа нет (удалять нечего — строка только создаётся). -->
            <input type="text" name="title" maxlength="300" value="<?= View::e($stitle) ?>" placeholder="Название подзадачи" data-autosave>
            <button class="res-link-btn sovet-danger js-sub-del" type="button" title="Удалить подзадачу" aria-label="Удалить подзадачу"<?= $stitle === '' ? ' hidden' : '' ?>>✕</button>
        </form>
    </div>
<?php endforeach; ?>
<form class="sovet-sub-add" method="post" action="/sovet/zadachi/<?= (int) $id ?>/podzadacha">
    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
    <input type="text" name="title" maxlength="300" placeholder="Название подзадачи">
    <button class="res-btn" type="submit" title="Добавить подзадачу" aria-label="Добавить подзадачу">+</button>
</form>
