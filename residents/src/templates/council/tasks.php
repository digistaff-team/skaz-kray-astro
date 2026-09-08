<?php
use SkazResidents\{Csrf, View};

/** Компактная карточка задачи (активная). */
$priorityClass = fn(string $p) => 'sovet-pri--' . ($p === 'высокая' ? 'high' : ($p === 'низкая' ? 'low' : 'mid'));
$statusClass   = fn(string $s) => 'sovet-st--' . ($s === 'выполнена' ? 'done' : ($s === 'в работе' ? 'progress' : 'new'));
$statusLabel   = fn(string $s) => ['новая' => 'Поставлена', 'в работе' => 'В работе', 'выполнена' => 'Выполнена'][$s] ?? $s;
$sorts = ['created' => 'по дате', 'progress' => 'по прогрессу', 'spent' => 'по расходам'];
?>
<h1>Текущие задачи</h1>
<p class="res-meta">Живой список задач по содержанию Терема.<br>Любой член совета может выбрать себе задачу, взять её в работу, отметить выполненной.</p>

<div class="sovet-toolbar">
    <div class="sovet-sorts">
        Сортировка:
        <?php foreach ($sorts as $key => $label): ?>
            <a class="sovet-sort<?= $sort === $key ? ' sovet-sort--active' : '' ?>" href="/sovet/zadachi?sort=<?= $key ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<details class="sovet-newtask">
    <summary class="res-btn">+ Новая задача</summary>
    <form class="res-form" method="post" action="/sovet/zadachi/novaya">
        <?= Csrf::field() ?>
        <input type="hidden" name="sort" value="<?= View::e($sort) ?>">
        <label>Что сделать<input type="text" name="title" maxlength="300" required></label>
        <label>Кто сделает
            <select name="assignee">
                <?php $names = array_column($members, 'name'); ?>
                <?php if (in_array($me, $names, true)): ?>
                    <option value="<?= View::e($me) ?>" selected><?= View::e($me) ?></option>
                    <?php foreach ($members as $m): if ($m['name'] === $me) { continue; } ?>
                        <option value="<?= View::e($m['name']) ?>"><?= View::e($m['name']) ?></option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="" selected>— не назначен —</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= View::e($m['name']) ?>"><?= View::e($m['name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </label>
        <label>До какого дня сделать<input type="date" name="due_date"></label>
        <label>Расходы, руб.<input type="number" name="spent" min="0" step="1" class="js-spent"></label>
        <label class="js-expense-cat" style="display:none">Статья расхода (для отчёта по бюджету)
            <select name="expense_category_id">
                <option value="">— не относить к бюджету —</option>
                <?php foreach (($expenseCats ?? []) as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Статус выполнения
            <select name="status">
                <option value="новая" selected>Поставлена</option>
                <option value="в работе">В работе</option>
                <option value="выполнена">Выполнена</option>
            </select>
        </label>
        <label>Как сделать и что учесть<textarea name="description"></textarea></label>
        <div class="sovet-form-actions">
            <button class="res-btn" type="submit">Добавить</button>
            <button class="res-btn res-btn--ghost" type="button" onclick="this.closest('details').removeAttribute('open')">Отменить</button>
        </div>
    </form>
</details>

<?php if (!$active): ?>
    <p class="res-meta sovet-empty">Нет активных задач. Добавьте первую 👆</p>
<?php endif; ?>

<?php foreach ($active as $t): $id = (int) $t['id']; ?>
    <details class="res-card sovet-task">
        <summary class="sovet-task-head">
            <span class="sovet-pri-dot <?= $priorityClass($t['priority']) ?>" title="Приоритет: <?= View::e($t['priority']) ?>" aria-label="Приоритет: <?= View::e($t['priority']) ?>"></span>
            <span class="sovet-task-title"><?= View::e($t['title']) ?></span>
            <span class="sovet-st <?= $statusClass($t['status']) ?>"><?= View::e($statusLabel($t['status'])) ?></span>
            <span class="sovet-task-assignee"><?= $t['assignee'] !== '' ? '👤 ' . View::e($t['assignee']) : '<span class="sovet-vacant">Вакантна</span>' ?></span>
            <span class="sovet-progress"><span class="sovet-progress-fill" style="width:<?= (int) $t['progress'] ?>%"></span></span>
            <button class="sovet-del-ico" type="submit" form="del-<?= $id ?>" title="Удалить задачу" aria-label="Удалить задачу" onclick="event.stopPropagation()"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 7V5h4v2"/><path d="M6 7l1 13h10l1-13"/></svg></button>
        </summary>

        <form id="del-<?= $id ?>" method="post" action="/sovet/zadachi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить задачу?');">
            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
        </form>

        <p class="sovet-task-meta">
            Исполнитель: <strong><?= $t['assignee'] !== '' ? View::e($t['assignee']) : '<span class="sovet-vacant">Вакантна</span>' ?></strong>
            · Автор: <?= $t['author'] !== '' ? View::e($t['author']) : '—' ?>
            · Подзадачи: <?= (int) $t['done_count'] ?>/<?= (int) $t['total_count'] ?>
            <?php if ((float) $t['spent'] > 0): ?> · Расходы: <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php endif; ?>
            · Прогресс: <?= (int) $t['progress'] ?>%<?php if (!empty($t['due_date'])): ?> · Срок: <?= View::e(ru_date((string) $t['due_date'])) ?><?php endif; ?>
        </p>
        <?php if (!empty($t['description'])): ?><p class="sovet-task-desc"><?= nl2br(View::e($t['description'])) ?></p><?php endif; ?>


        <form id="edit-<?= $id ?>" class="res-form sovet-edit" method="post" action="/sovet/zadachi/<?= $id ?>/obnovit">
            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
            <label>Что сделать<input type="text" name="title" maxlength="300" value="<?= View::e($t['title']) ?>"></label>
            <label>Кто сделает
                <select name="assignee">
                    <option value="">— не назначен —</option>
                    <?php
                        $names = array_column($members, 'name');
                        $cur = (string) $t['assignee'];
                        if ($cur !== '' && !in_array($cur, $names, true)): ?>
                        <option value="<?= View::e($cur) ?>" selected><?= View::e($cur) ?></option>
                    <?php endif; ?>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= View::e($m['name']) ?>"<?= $cur === $m['name'] ? ' selected' : '' ?>><?= View::e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="sovet-edit-row">
                <label>Приоритет
                    <select name="priority">
                        <?php foreach (['низкая' => 'Низкий', 'средняя' => 'Средний', 'высокая' => 'Высокий'] as $pv => $pl): ?>
                            <option value="<?= $pv ?>"<?= $t['priority'] === $pv ? ' selected' : '' ?>><?= $pl ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Статус выполнения
                    <select name="status">
                        <?php foreach (['новая','в работе','выполнена'] as $s): ?>
                            <option value="<?= $s ?>"<?= $t['status'] === $s ? ' selected' : '' ?>><?= $statusLabel($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="sovet-edit-row">
                <label>Крайний срок<input type="date" name="due_date" value="<?= View::e($t['due_date'] ?? '') ?>"></label>
                <label>Прогресс
                    <?php $curProg = (int) $t['progress']; $progOpts = [20, 50, 80]; ?>
                    <select name="progress">
                        <?php if (!in_array($curProg, $progOpts, true)): ?><option value="<?= $curProg ?>" selected><?= $curProg ?>%</option><?php endif; ?>
                        <?php foreach ($progOpts as $p): ?><option value="<?= $p ?>"<?= $curProg === $p ? ' selected' : '' ?>><?= $p ?>%</option><?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>Расходы, руб.<input type="number" name="spent" min="0" step="1" class="js-spent" value="<?= rtrim(rtrim(number_format((float) $t['spent'], 2, '.', ''), '0'), '.') ?>"></label>
            <?php $curCat = (int) ($t['expense_category_id'] ?? 0); $activeIds = array_map('intval', array_column($expenseCats ?? [], 'id')); ?>
            <label class="js-expense-cat"<?= (float) $t['spent'] > 0 ? '' : ' style="display:none"' ?>>Статья расхода (для отчёта по бюджету)
                <select name="expense_category_id">
                    <option value="">— не относить к бюджету —</option>
                    <?php if ($curCat > 0 && !in_array($curCat, $activeIds, true)): ?><option value="<?= $curCat ?>" selected>Текущая статья (архив)</option><?php endif; ?>
                    <?php foreach (($expenseCats ?? []) as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $curCat === (int) $c['id'] ? ' selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Как сделать и что учесть<textarea name="description"><?= View::e($t['description'] ?? '') ?></textarea></label>
        </form>

        <div class="sovet-subs">
            <h3 class="sovet-h3">Подзадачи</h3>
            <?php foreach ($t['subtasks'] as $s): $sid = (int) $s['id']; $done = (int) $s['done'] === 1; ?>
                <div class="sovet-sub<?= $done ? ' sovet-sub--done' : '' ?>">
                    <form method="post" action="/sovet/podzadacha/<?= $sid ?>/pereklyuchit">
                        <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                        <button class="sovet-check" type="submit" title="Отметить"><?= $done ? '☑' : '☐' ?></button>
                    </form>
                    <form class="sovet-sub-rename" method="post" action="/sovet/podzadacha/<?= $sid ?>/pereimenovat">
                        <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                        <input type="text" name="title" maxlength="300" value="<?= View::e($s['title']) ?>">
                        <button class="res-link-btn" type="submit">✓</button>
                    </form>
                    <form method="post" action="/sovet/podzadacha/<?= $sid ?>/udalit">
                        <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                        <button class="res-link-btn sovet-danger" type="submit">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <form class="sovet-sub-add" method="post" action="/sovet/zadachi/<?= $id ?>/podzadacha">
                <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                <input type="text" name="title" maxlength="300" placeholder="Новая подзадача">
                <button class="res-btn" type="submit" title="Добавить подзадачу" aria-label="Добавить подзадачу">+</button>
            </form>
        </div>

        <button class="res-btn sovet-save" type="submit" form="edit-<?= $id ?>">Сохранить</button>
    </details>
<?php endforeach; ?>

<?php if ($archive): ?>
    <details class="sovet-acc sovet-archive">
    <summary><h2 class="sovet-archive-h">Выполненные задачи · <?= count($archive) ?></h2></summary>
    <?php foreach ($archive as $t): $id = (int) $t['id']; ?>
        <div class="res-card sovet-arch">
            <span class="sovet-arch-title"><?= View::e($t['title']) ?></span>
            <span class="res-meta"><?= $t['assignee'] !== '' ? View::e($t['assignee']) : '—' ?><?php if ((float) $t['spent'] > 0): ?> · <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php endif; ?></span>
            <span class="sovet-arch-actions">
                <form method="post" action="/sovet/zadachi/<?= $id ?>/vernut">
                    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                    <button class="sovet-arch-ico" type="submit" title="Вернуть в работу" aria-label="Вернуть в работу">↩️</button>
                </form>
                <form method="post" action="/sovet/zadachi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить задачу?');">
                    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                    <button class="sovet-arch-ico" type="submit" title="Удалить" aria-label="Удалить">🗑</button>
                </form>
            </span>
        </div>
    <?php endforeach; ?>
    </details>
<?php endif; ?>

<script>
// «Статья расхода» показывается только когда указана ненулевая сумма затрат.
(function () {
  function sync(inp) {
    var form = inp.closest('form'); if (!form) return;
    var cat = form.querySelector('.js-expense-cat'); if (!cat) return;
    var v = parseFloat((inp.value || '0').replace(',', '.'));
    cat.style.display = (v > 0) ? '' : 'none';
  }
  Array.prototype.forEach.call(document.querySelectorAll('.js-spent'), function (inp) {
    sync(inp);
    inp.addEventListener('input', function () { sync(inp); });
  });
})();
</script>
