<?php use SkazResidents\View; ?>
<div class="tool-head">
    <h1>Совместные поездки</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/poezdki/novaya">+ Предложить поездку</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/poezdki/moi">Мои поездки</a>
    </div>
</div>
<p class="res-meta">Соседи подвозят соседей. Водитель публикует поездку и число свободных мест, вы бронируете место — водитель подтверждает.</p>

<form class="tool-filters" method="get" action="/poselenie/poezdki">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Поиск по маршруту (откуда/куда)">
    <input type="date" name="date" value="<?= View::e($date) ?>">
    <button class="res-btn" type="submit">Найти</button>
</form>

<?php if (!$trips): ?>
    <p class="res-meta tool-empty">Пока нет предстоящих поездок. <a href="/poselenie/poezdki/novaya">Предложите свою</a>.</p>
<?php endif; ?>

<?php foreach ($trips as $t): $id = (int) $t['id']; ?>
    <a class="res-card trip-card" href="/poselenie/poezdki/<?= $id ?>">
        <div class="trip-route"><?= View::e($t['origin']) ?> <span class="trip-arrow">→</span> <?= View::e($t['destination']) ?></div>
        <div class="trip-meta">
            <span class="trip-when"><?= View::e(ru_date((string) $t['trip_date'])) ?><?php if (!empty($t['trip_time'])): ?>, <?= View::e($t['trip_time']) ?><?php endif; ?></span>
            <span class="trip-seats"><?= (int) $t['seats_free'] ?> из <?= (int) $t['seats_total'] ?> мест</span>
        </div>
        <div class="res-meta">Водитель: <?= View::e($t['driver_name']) ?><?php if (!empty($t['note'])): ?> · <?= View::e(mb_strimwidth((string) $t['note'], 0, 80, '…')) ?><?php endif; ?></div>
    </a>
<?php endforeach; ?>
