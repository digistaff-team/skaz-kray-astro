<?php use SkazResidents\View; ?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<?php $tab = 'dostavka'; require __DIR__ . '/../partials/trip-tabs.php'; ?>
<div class="tool-head">
    <h1>Нужно привезти</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/dostavka/novaya">+ Попросить привезти</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/dostavka/moi">Мои доставки</a>
    </div>
</div>
<p class="res-meta">Попросите соседа, который едет в город, купить или забрать заказ. Заявку берёт первый, кто откликнется; сумму по чеку он укажет, когда привезёт.</p>

<nav class="tool-filters" aria-label="Тип заявки">
    <a class="res-btn<?= $kind === '' ? '' : ' res-btn--ghost' ?>" href="/poselenie/dostavka">Все</a>
    <a class="res-btn<?= $kind === 'buy' ? '' : ' res-btn--ghost' ?>" href="/poselenie/dostavka?kind=buy">Купить</a>
    <a class="res-btn<?= $kind === 'pickup' ? '' : ' res-btn--ghost' ?>" href="/poselenie/dostavka?kind=pickup">Забрать</a>
</nav>

<?php if (!$items): ?>
    <p class="res-meta tool-empty">Сейчас никто ничего не просит. <a href="/poselenie/dostavka/novaya">Попросите сами</a>.</p>
<?php endif; ?>

<?php foreach ($items as $d): ?>
    <a class="res-card trip-card" href="/poselenie/dostavka/<?= (int) $d['id'] ?>">
        <div class="trip-route"><?= View::e(delivery_kind_label((string) $d['kind'])) ?> · <?= View::e($d['place']) ?></div>
        <div class="trip-meta">
            <span class="trip-when"><?= ($d['need_by'] ?? '') !== '' ? 'к ' . View::e(ru_date((string) $d['need_by'])) : 'без срока' ?></span>
        </div>
        <div class="res-meta"><?= View::e(mb_strimwidth((string) $d['what'], 0, 90, '…')) ?> · <?= View::e($d['req_name']) ?></div>
    </a>
<?php endforeach; ?>
