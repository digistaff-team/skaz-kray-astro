<?php
use SkazResidents\{Csrf, View};

/** Компактная карточка задачи (активная). */
$priorityClass = fn(string $p) => 'sovet-pri--' . ($p === 'высокая' ? 'high' : ($p === 'низкая' ? 'low' : 'mid'));
$statusClass   = fn(string $s) => 'sovet-st--' . ($s === 'выполнена' ? 'done' : ($s === 'в работе' ? 'progress' : 'new'));
$sorts = ['priority' => 'по важности', 'created' => 'по дате', 'progress' => 'по прогрессу', 'spent' => 'по расходам'];
?>
<h1>Текущие задачи</h1>
<p class="res-meta">Живой список задач по содержанию Терема. Любой член совета может добавить задачу, взять её в работу, отметить выполненной.</p>

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
        <label>Задача<input type="text" name="title" maxlength="300" required></label>
        <label>Исполнитель<input type="text" name="assignee" maxlength="160"></label>
        <label>Комментарий<textarea name="description"></textarea></label>
        <button class="res-btn" type="submit">Добавить</button>
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
            <span class="sovet-st <?= $statusClass($t['status']) ?>"><?= View::e($t['status']) ?></span>
            <span class="sovet-progress"><span class="sovet-progress-fill" style="width:<?= (int) $t['progress'] ?>%"></span></span>
        </summary>

        <p class="sovet-task-meta">
            Исполнитель: <strong><?= $t['assignee'] !== '' ? View::e($t['assignee']) : '<span class="res-meta">не назначен</span>' ?></strong>
            · Автор: <?= $t['author'] !== '' ? View::e($t['author']) : '—' ?>
            · Подзадачи: <?= (int) $t['done_count'] ?>/<?= (int) $t['total_count'] ?>
            <?php if ((float) $t['spent'] > 0): ?> · Расходы: <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php endif; ?>
            · Прогресс: <?= (int) $t['progress'] ?>%
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
            <label>Задача<input type="text" name="title" maxlength="300" value="<?= View::e($t['title']) ?>"></label>
            <div class="sovet-edit-row">
                <label>Исполнитель<input type="text" name="assignee" maxlength="160" value="<?= View::e($t['assignee']) ?>"></label>
                <label>Приоритет
                    <select name="priority">
                        <?php foreach (['низкая','средняя','высокая'] as $p): ?>
                            <option value="<?= $p ?>"<?= $t['priority'] === $p ? ' selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Статус
                    <select name="status">
                        <?php foreach (['новая','в работе','выполнена'] as $s): ?>
                            <option value="<?= $s ?>"<?= $t['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="sovet-edit-row">
                <label>Прогресс, %<input type="number" name="progress" min="0" max="100" step="5" value="<?= (int) $t['progress'] ?>"></label>
                <label>Затрачено, ₽<input type="number" name="spent" min="0" step="1" value="<?= rtrim(rtrim(number_format((float) $t['spent'], 2, '.', ''), '0'), '.') ?>"></label>
            </div>
            <label>Комментарий<textarea name="description"><?= View::e($t['description'] ?? '') ?></textarea></label>
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
