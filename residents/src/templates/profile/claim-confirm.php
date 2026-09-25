<?php
use SkazResidents\{Csrf, View};
/** @var array $household @var bool $hasMembers @var bool $occupied */
$gladeName = static function (string $g): string {
    return preg_match('~^\(\d+\)\s*(.+)$~u', trim($g), $m) ? trim($m[1]) : $g;
};
$h = $household;
$occupied = $occupied ?? false;
?>
<?php $backFallback = '/poselenie/moye-pomestie/vybor'; require __DIR__ . '/../partials/back.php'; ?>
<h1><?= $occupied ? 'Попроситься в совладельцы?' : 'Это ваше поместье?' ?></h1>
<div class="prof-card">
    <b class="prof-name"><?= $h['estate_name'] !== '' ? 'Поместье ' . View::e($h['estate_name']) : 'Поместье' ?></b>
    <div class="res-meta">
        Поляна <?= View::e($gladeName($h['glade'])) ?><?php if ($h['plot'] !== ''): ?>, участок <?= View::e($h['plot']) ?><?php endif; ?>
    </div>
</div>
<?php if ($occupied): ?>
    <p class="res-meta">Это поместье уже ведёт кто-то из вашей семьи. Отправьте заявку в совладельцы — когда владелец её подтвердит, вы вместе сможете править данные поместья, у каждого свой вход.</p>
    <p class="res-meta">Если это вы сами, просто вошли через MAX, а раньше входили через Telegram, — отправьте заявку и подтвердите её из Telegram: входы объединятся в один аккаунт.</p>
<?php endif; ?>

<form class="res-form" method="post" action="/poselenie/moye-pomestie/vybor/<?= (int) $h['id'] ?>" style="margin-top:1rem">
    <?= Csrf::field() ?>
    <?php if ($hasMembers): ?>
        <label>Для подтверждения введите вашу фамилию
            <input type="text" name="surname" placeholder="Фамилия" required autocomplete="off">
        </label>
        <p class="res-meta">Мы проверим, что такая фамилия есть среди жителей этого поместья. Данные других семей при этом не показываются.</p>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $occupied ? 'Отправить заявку владельцу' : 'Да, это наше поместье — привязать' ?></button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie/vybor">Выбрать другое</a>
</form>
