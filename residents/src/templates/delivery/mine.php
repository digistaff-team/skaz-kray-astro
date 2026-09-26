<?php
use SkazResidents\{Csrf, View};
$row = static function (array $d, string $who): string {
    return '<a href="/poselenie/dostavka/' . (int) $d['id'] . '"><strong>' . View::e(delivery_kind_label((string) $d['kind'])) . ' · '
        . View::e($d['place']) . '</strong></a> <span class="tool-st ' . delivery_status_class((string) $d['status']) . '">'
        . View::e(delivery_status_label((string) $d['status'])) . '</span> <span class="res-meta">' . View::e($who) . '</span>';
};
?>
<?php $backFallback = '/poselenie/dostavka'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Мои доставки</h1>
<p class="res-meta"><a class="res-btn" href="/poselenie/dostavka/novaya">+ Попросить привезти</a> <a class="res-btn res-btn--ghost" href="/poselenie/dostavka">Все заявки</a></p>

<?php if ($incoming): ?>
<section>
    <h2>Просьбы к моим поездкам</h2>
    <?php foreach ($incoming as $d): $id = (int) $d['id']; ?>
        <div class="res-card tool-mine-row">
            <div><?= $row($d, $d['req_name'] . ' · ' . $d['origin'] . ' → ' . $d['destination']) ?></div>
            <div class="tool-mine-actions">
                <form method="post" action="/poselenie/dostavka/<?= $id ?>/vzyat"><?= Csrf::field() ?><button class="res-btn" type="submit">Возьму</button></form>
                <form method="post" action="/poselenie/dostavka/<?= $id ?>/ne-smogu"><?= Csrf::field() ?><button class="res-link-btn" type="submit">Не смогу</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section>
    <h2>Я прошу</h2>
    <?php if (!$asked): ?><p class="res-meta">Вы пока ни о чём не просили.</p><?php endif; ?>
    <?php foreach ($asked as $d): ?>
        <div class="res-card"><?= $row($d, ($d['car_name'] ?? '') !== '' ? 'везёт ' . $d['car_name'] : '') ?></div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Я везу</h2>
    <?php if (!$carrying): ?><p class="res-meta">Вы пока ничего не взяли. <a href="/poselenie/dostavka">Посмотрите доску</a>.</p><?php endif; ?>
    <?php foreach ($carrying as $d): ?>
        <div class="res-card"><?= $row($d, 'для ' . $d['req_name']) ?></div>
    <?php endforeach; ?>
</section>
