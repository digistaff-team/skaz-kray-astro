<?php
use SkazResidents\View;
/** @var array $organized @var array $participating */
?>
<?php $backFallback = '/poselenie/zakupki'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Мои закупки</h1>
<p class="res-meta"><a class="res-btn" href="/poselenie/zakupki/novaya">+ Открыть закупку</a> <a class="res-btn res-btn--ghost" href="/poselenie/zakupki">Все закупки</a></p>

<section>
    <h2>Я организую</h2>
    <?php if (!$organized): ?><p class="res-meta">Вы пока не открывали закупок.</p><?php endif; ?>
    <div class="buy-list">
        <?php foreach ($organized as $p): ?>
            <?php require __DIR__ . '/_card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>

<section>
    <h2>Я участвую</h2>
    <?php if (!$participating): ?><p class="res-meta">Вы пока никуда не записались.</p><?php endif; ?>
    <div class="buy-list">
        <?php foreach ($participating as $p): ?>
            <?php require __DIR__ . '/_card.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
