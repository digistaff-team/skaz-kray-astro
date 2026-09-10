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
    <summary class="sovet-addlink">+ Новая задача</summary>
    <form class="res-form" method="post" action="/sovet/zadachi/novaya" enctype="multipart/form-data">
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
        <div class="sovet-edit-row">
            <label>Крайний срок<input type="date" name="due_date"></label>
            <label>Приоритет
                <select name="priority">
                    <?php foreach (['низкая' => 'Низкий', 'средняя' => 'Средний', 'высокая' => 'Высокий'] as $pv => $pl): ?>
                        <option value="<?= $pv ?>"<?= $pv === 'средняя' ? ' selected' : '' ?>><?= $pl ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Расходы, руб.<input type="number" name="spent" min="0" step="1" class="js-spent"></label>
        <label class="js-expense-cat" style="display:none">Статья расхода (для отчёта по бюджету)
            <select name="expense_category_id">
                <option value="">— не относить к бюджету —</option>
                <?php foreach (($expenseCats ?? []) as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <!-- Фото задачи: выбор + клиентское превью (как в дневнике и профиле). -->
        <label class="sovet-addlink">+ Добавить фото
            <input type="file" name="photos[]" id="taskPhotos" accept="image/*" multiple hidden>
        </label>
        <div id="taskPhotoPreview" class="photo-preview"></div>
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
            <?php if ((float) $t['spent'] > 0): ?> · Расходы: <?= number_format((float) $t['spent'], 0, '.', ' ') ?> ₽<?php
                // Статус одобрения расхода казначеём — только когда есть статья (иначе расход не учитывается).
                $es = (string) ($t['expense_status'] ?? 'none');
                $esLabel = ['pending' => '⏳ на одобрении', 'approved' => '✅ одобрено', 'rejected' => '🚫 отклонено'][$es] ?? '';
                if ($esLabel !== '' && (int) ($t['expense_category_id'] ?? 0) > 0): ?> <span class="res-meta">(<?= $esLabel ?>)</span><?php endif; ?>
            <?php endif; ?>
            · Прогресс: <?= (int) $t['progress'] ?>%<?php if (!empty($t['due_date'])): ?> · Срок: <?= View::e(ru_date((string) $t['due_date'])) ?><?php endif; ?>
        </p>
        <?php if (!empty($t['description'])): ?><p class="sovet-task-desc"><?= nl2br(View::e($t['description'])) ?></p><?php endif; ?>


        <?php if (!empty($t['photos'])): ?>
            <div class="res-meta">Фото (× — удалить):</div>
            <div class="photo-preview">
                <?php foreach ($t['photos'] as $img): ?>
                    <form class="photo-uploaded" method="post" action="/sovet/zadachi/<?= $id ?>/foto/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                        <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                        <img class="photo-thumb" src="<?= View::e(entry_image_url($img['path'])) ?>" alt="" loading="lazy">
                        <button type="submit" class="photo-del" title="Удалить фото">×</button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
                    <button class="sovet-arch-ico" type="submit" title="Вернуть в работу" aria-label="Вернуть в работу"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-2"/></svg></button>
                </form>
                <form method="post" action="/sovet/zadachi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить задачу?');">
                    <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                    <button class="sovet-arch-ico sovet-arch-ico--del" type="submit" title="Удалить" aria-label="Удалить"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M10 7V5h4v2"/><path d="M6 7l1 13h10l1-13"/></svg></button>
                </form>
            </span>
            <?php if (!empty($t['photos'])): ?>
                <div class="photo-preview sovet-arch-photos">
                    <?php foreach ($t['photos'] as $img): ?>
                        <form class="photo-uploaded" method="post" action="/sovet/zadachi/<?= $id ?>/foto/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                            <?= Csrf::field() ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>">
                            <img class="photo-thumb" src="<?= View::e(entry_image_url($img['path'])) ?>" alt="" loading="lazy">
                            <button type="submit" class="photo-del" title="Удалить фото">×</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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

<script>
// Накопительный выбор фото с превью — та же логика, что в дневнике и профиле.
(function () {
  var inp = document.getElementById('taskPhotos'), box = document.getElementById('taskPhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    Array.prototype.forEach.call(inp.files, function (f) { if (/^image\//.test(f.type)) { dt.items.add(f); } });
    inp.files = dt.files;
    render();
  });
  function render() {
    box.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, idx) {
      var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
      var img = document.createElement('img'); img.className = 'photo-thumb'; img.src = URL.createObjectURL(f);
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
      btn.addEventListener('click', function () { dt.items.remove(idx); inp.files = dt.files; render(); });
      wrap.appendChild(img); wrap.appendChild(btn); box.appendChild(wrap);
    });
  }
})();
</script>
