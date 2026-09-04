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
        <label>Стоимость работ, ₽<input type="number" name="spent" min="0" step="1"></label>
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
            <span class="sovet-pri <?= $priorityClass($t['priority']) ?>"><?= $t['priority'] === 'высокая' ? '🔥 ' : '' ?><?= View::e($t['priority']) ?></span>
            <span class="sovet-task-title"><?= View::e($t['title']) ?></span>
            <span class="sovet-st <?= $statusClass($t['status']) ?>"><?= View::e($statusLabel($t['status'])) ?></span>
            <span class="sovet-progress"><span class="sovet-progress-fill" style="width:<?= (int) $t['progress'] ?>%"></span></span>
        </summary>

        <p class="sovet-task-meta">
            Исполнитель: <strong><?= $t['assignee'] !== '' ? View::e($t['assignee']) : '<span class="res-meta">не назначен</span>' ?></strong>
            · Автор: <?= $t['author'] !== '' ? View::e($t['author']) : '—' ?>
            · Подзадачи: <?= (int) $t['done_count'] ?>/<?= (int) $t['total_count'] ?>
            <?php if ((float) $t['spent'] > 0): ?> · Расходы: <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php endif; ?>
            · Прогресс: <?= (int) $t['progress'] ?>%<?php if (!empty($t['due_date'])): ?> · Срок: <?= View::e(ru_date((string) $t['due_date'])) ?><?php endif; ?>
        </p>
        <?php if (!empty($t['description'])): ?><p class="sovet-task-desc"><?= nl2br(View::e($t['description'])) ?></p><?php endif; ?>

        <div class="sovet-quick">
            <form method="post" action="/sovet/zadachi/<?= $id ?>/vzyat">
                <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                <button class="res-btn res-btn--ghost" type="submit">Взять на себя</button>
            </form>
            <form method="post" action="/sovet/zadachi/<?= $id ?>/gotovo">
                <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                <button class="res-btn res-btn--ghost" type="submit">Выполнено</button>
            </form>
            <form method="post" action="/sovet/zadachi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить задачу?');">
                <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                <button class="res-link-btn sovet-danger" type="submit">удалить</button>
            </form>
        </div>

        <form class="res-form sovet-edit" method="post" action="/sovet/zadachi/<?= $id ?>/obnovit">
            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
            <label>Что сделать<input type="text" name="title" maxlength="300" value="<?= View::e($t['title']) ?>"></label>
            <div class="sovet-edit-row">
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
                <label>Приоритет
                    <select name="priority">
                        <?php foreach (['низкая','средняя','высокая'] as $p): ?>
                            <option value="<?= $p ?>"<?= $t['priority'] === $p ? ' selected' : '' ?>><?= $p ?></option>
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
                <label>Прогресс, %<input type="number" name="progress" min="0" max="100" step="5" value="<?= (int) $t['progress'] ?>"></label>
                <label>Затрачено, ₽<input type="number" name="spent" min="0" step="1" value="<?= rtrim(rtrim(number_format((float) $t['spent'], 2, '.', ''), '0'), '.') ?>"></label>
            </div>
            <label>До какого дня сделать<input type="date" name="due_date" value="<?= View::e($t['due_date'] ?? '') ?>"></label>
            <label>Как сделать и что учесть<textarea name="description"><?= View::e($t['description'] ?? '') ?></textarea></label>
            <label>Контакты специалистов<textarea name="contacts" placeholder="Электрик Виктор, +7 900 000-00-00"><?= View::e($t['contacts'] ?? '') ?></textarea></label>
            <label>Ссылки на товары<textarea name="links" placeholder="https://…"><?= View::e($t['links'] ?? '') ?></textarea></label>
            <button class="res-btn" type="submit">Сохранить</button>
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
                <button class="res-btn res-btn--ghost" type="submit">Добавить</button>
            </form>
        </div>
    </details>
<?php endforeach; ?>

<?php if ($archive): ?>
    <h2 class="sovet-archive-h">Архив выполненных · <?= count($archive) ?></h2>
    <?php foreach ($archive as $t): $id = (int) $t['id']; ?>
        <div class="res-card sovet-arch">
            <span class="sovet-arch-title"><?= View::e($t['title']) ?></span>
            <span class="res-meta"><?= $t['assignee'] !== '' ? View::e($t['assignee']) : '—' ?><?php if ((float) $t['spent'] > 0): ?> · <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php endif; ?></span>
            <span class="sovet-arch-actions">
                <form method="post" action="/sovet/zadachi/<?= $id ?>/vernut">
                    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                    <button class="res-link-btn" type="submit">вернуть</button>
                </form>
                <form method="post" action="/sovet/zadachi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить задачу?');">
                    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                    <button class="res-link-btn sovet-danger" type="submit">удалить</button>
                </form>
            </span>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
