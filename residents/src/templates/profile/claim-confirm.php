<?php
use SkazResidents\{Csrf, View};
/** @var array $household @var bool $hasMembers */
$gladeName = static function (string $g): string {
    return preg_match('~^\(\d+\)\s*(.+)$~u', trim($g), $m) ? trim($m[1]) : $g;
};
$h = $household;
?>
<h1>Это ваше поместье?</h1>
<div class="prof-card">
    <b class="prof-name"><?= $h['estate_name'] !== '' ? 'Поместье ' . View::e($h['estate_name']) : 'Поместье' ?></b>
    <div class="res-meta">
        Поляна <?= View::e($gladeName($h['glade'])) ?><?php if ($h['plot'] !== ''): ?>, участок <?= View::e($h['plot']) ?><?php endif; ?>
    </div>
</div>

<form class="res-form" method="post" action="/poselenie/moye-pomestie/vybor/<?= (int) $h['id'] ?>" style="margin-top:1rem">
    <?= Csrf::field() ?>
    <?php if ($hasMembers): ?>
        <label>Для подтверждения введите вашу фамилию
            <input type="text" name="surname" placeholder="Фамилия" required autocomplete="off">
        </label>
        <p class="res-meta">Мы проверим, что такая фамилия есть среди жителей этого поместья. Данные других семей при этом не показываются.</p>
    <?php endif; ?>
    <button class="res-btn" type="submit">Да, это наше поместье — привязать</button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie/vybor">Выбрать другое</a>
</form>
