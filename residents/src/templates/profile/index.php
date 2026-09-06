<?php
use SkazResidents\{View, Csrf};
/** @var array $household @var array $members @var array $cars @var array $pets */
$gladeName = static function (string $g): string {
    return preg_match('~^\(\d+\)\s*(.+)$~u', trim($g), $m) ? trim($m[1]) : $g;
};
$gladeNum = static function (string $g): int {
    return preg_match('~^\((\d+)\)~u', trim($g), $m) ? (int) $m[1] : 0;
};
$vkUrl = static function (string $v): string {
    $v = trim($v);
    if ($v === '') { return ''; }
    return preg_match('~^https?://~i', $v) ? $v : 'https://' . ltrim($v, '/');
};
// Иконки действий (наследуют цвет через currentColor).
$pencil = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
$trash  = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
$h = $household;
?>
<p class="res-meta"><a class="res-btn res-btn--ghost" href="/poselenie/app">← На главную</a></p>
<div class="prof-estate">
    <h1 class="prof-title" id="estateTitle">
        <span><?= $h['estate_name'] !== '' ? 'Поместье ' . View::e($h['estate_name']) : 'Поместье' ?></span>
        <button type="button" class="res-icon prof-estate-edit" id="estateEditBtn" title="Изменить название" aria-label="Изменить название"><?= $pencil ?></button>
    </h1>
    <form class="res-form prof-estate-form" id="estateForm" method="post" action="/poselenie/moye-pomestie/nazvanie" hidden>
        <?= Csrf::field() ?>
        <label>Название поместья
            <input type="text" name="estate_name" value="<?= View::e((string) $h['estate_name']) ?>" placeholder="напр. АгудариЯ" autocomplete="off">
        </label>
        <div class="prof-estate-actions">
            <button class="res-btn" type="submit">Сохранить</button>
            <button class="res-btn res-btn--ghost" type="button" id="estateCancelBtn">Отмена</button>
        </div>
    </form>
</div>
<p class="res-meta">
    Поляна <?= View::e($gladeName($h['glade'])) ?> (<?= $gladeNum($h['glade']) ?>)<?php if ($h['plot'] !== ''): ?>, участок <?= $gladeNum($h['glade']) ?>-<?= View::e($h['plot']) ?><?php endif; ?>
</p>

<div class="prof-sec-head"><h2>Жители поместья</h2></div>
<?php if (!$members): ?><p class="res-meta">Пока никого не добавлено.</p><?php endif; ?>
<div class="prof-list">
    <?php foreach ($members as $m): ?>
        <div class="prof-card">
            <div class="prof-card-top">
                <b class="prof-name"><?= View::e($m['full_name']) ?></b>
                <span class="prof-actions">
                    <a class="res-link res-icon" href="/poselenie/moye-pomestie/zhitel/<?= (int) $m['id'] ?>/redaktirovat" title="Изменить" aria-label="Изменить"><?= $pencil ?></a>
                    <form method="post" action="/poselenie/moye-pomestie/zhitel/<?= (int) $m['id'] ?>/udalit" onsubmit="return confirm('Удалить <?= View::e(addslashes($m['full_name'])) ?> из поместья?')">
                        <?= Csrf::field() ?><button type="submit" class="res-link res-link--danger res-icon" title="Удалить" aria-label="Удалить"><?= $trash ?></button>
                    </form>
                </span>
            </div>
            <div class="prof-fields">
                <?php if ($m['birth_raw'] !== ''): ?><span>Дата рождения: <?= View::e($m['birth_raw']) ?></span><?php endif; ?>
                <?php if ($m['phone'] !== ''): ?><span>Телефон: <?= View::e($m['phone']) ?></span><?php endif; ?>
                <?php if ($m['email'] !== ''): ?><span>Email: <?= View::e($m['email']) ?></span><?php endif; ?>
                <?php if ($m['vk'] !== ''): ?><span>VK: <a href="<?= View::e($vkUrl($m['vk'])) ?>" target="_blank" rel="noopener"><?= View::e($m['vk']) ?></a></span><?php endif; ?>
                <?php if (!empty($m['skills'])): ?><span>Деятельность, навыки: <?= View::e($m['skills']) ?></span><?php endif; ?>
                <?php if (!empty($m['community_role'])): ?><span>Для поселения: <?= View::e($m['community_role']) ?></span><?php endif; ?>
                <?php if ($m['hometown'] !== ''): ?><span>Родной город: <?= View::e($m['hometown']) ?></span><?php endif; ?>
                <?php if ($m['residence'] !== ''): ?><span>Место проживания: <?= View::e($m['residence']) ?></span><?php endif; ?>
                <?php if ($m['moved_text'] !== ''): ?><span>Дата переезда: <?= View::e($m['moved_text']) ?></span><?php endif; ?>
                <?php if (!empty($m['comment'])): ?><span>Комментарий: <?= View::e($m['comment']) ?></span><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<a class="res-btn prof-add" href="/poselenie/moye-pomestie/zhitel/novyy">+ Добавить жителя</a>

<div class="prof-sec-head"><h2>Автомобили</h2></div>
<?php if (!$cars): ?><p class="res-meta">Автомобили не указаны.</p><?php endif; ?>
<div class="prof-list">
    <?php foreach ($cars as $c): ?>
        <div class="prof-card">
            <div class="prof-card-top">
                <b class="prof-name"><?= View::e($c['title']) ?><?php if ($c['plate'] !== ''): ?> · <?= View::e($c['plate']) ?><?php endif; ?></b>
                <span class="prof-actions">
                    <a class="res-link res-icon" href="/poselenie/moye-pomestie/avto/<?= (int) $c['id'] ?>/redaktirovat" title="Изменить" aria-label="Изменить"><?= $pencil ?></a>
                    <form method="post" action="/poselenie/moye-pomestie/avto/<?= (int) $c['id'] ?>/udalit" onsubmit="return confirm('Удалить автомобиль?')">
                        <?= Csrf::field() ?><button type="submit" class="res-link res-link--danger res-icon" title="Удалить" aria-label="Удалить"><?= $trash ?></button>
                    </form>
                </span>
            </div>
            <?php if ($c['note'] !== ''): ?><div class="prof-fields"><span><?= View::e($c['note']) ?></span></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<a class="res-btn prof-add" href="/poselenie/moye-pomestie/avto/novyy">+ Добавить автомобиль</a>

<div class="prof-sec-head"><h2>Питомцы</h2></div>
<?php if (!$pets): ?><p class="res-meta">Питомцы не указаны.</p><?php endif; ?>
<div class="prof-list">
    <?php foreach ($pets as $pet): ?>
        <div class="prof-card">
            <div class="prof-card-top">
                <b class="prof-name"><?= View::e($pet['name']) ?><?php if ($pet['kind'] !== ''): ?> · <?= View::e($pet['kind']) ?><?php endif; ?></b>
                <span class="prof-actions">
                    <a class="res-link res-icon" href="/poselenie/moye-pomestie/pitomec/<?= (int) $pet['id'] ?>/redaktirovat" title="Изменить" aria-label="Изменить"><?= $pencil ?></a>
                    <form method="post" action="/poselenie/moye-pomestie/pitomec/<?= (int) $pet['id'] ?>/udalit" onsubmit="return confirm('Удалить питомца?')">
                        <?= Csrf::field() ?><button type="submit" class="res-link res-link--danger res-icon" title="Удалить" aria-label="Удалить"><?= $trash ?></button>
                    </form>
                </span>
            </div>
            <?php if ($pet['note'] !== ''): ?><div class="prof-fields"><span><?= View::e($pet['note']) ?></span></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<a class="res-btn prof-add" href="/poselenie/moye-pomestie/pitomec/novyy">+ Добавить питомца</a>

<script>
(function () {
  var btn = document.getElementById('estateEditBtn');
  var title = document.getElementById('estateTitle');
  var form = document.getElementById('estateForm');
  var cancel = document.getElementById('estateCancelBtn');
  if (!btn || !title || !form) { return; }
  btn.addEventListener('click', function () {
    title.hidden = true; form.hidden = false;
    var i = form.querySelector('input[name="estate_name"]');
    if (i) { i.focus(); i.select(); }
  });
  if (cancel) { cancel.addEventListener('click', function () { form.hidden = true; title.hidden = false; }); }
})();
</script>
