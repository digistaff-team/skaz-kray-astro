<?php use SkazResidents\{View, Csrf}; /** @var array $products */ ?>
<?php $backFallback = '/poselenie/yarmarka'; require __DIR__ . '/../partials/back.php'; ?>
<div class="tool-head">
    <h1>Моя витрина</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka">Товары и услуги соседей</a>
    </div>
</div>
<p class="res-meta">Ваши товары и услуги публикуются по вашему выбору только для соседей или для всех на сайте.</p>

<?php if (!$products): ?>
    <p class="res-meta tool-empty">Ваша витрина пока пуста.<br><a href="/poselenie/yarmarka/novyy">Добавьте свои товары и услуги</a></p>
<?php endif; ?>

<?php foreach ($products as $p): ?>
    <div class="res-card cab-item">
        <div class="cab-item-head">
            <strong class="cab-item-title"><?= View::e($p['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($p['status']) ?>"><?= View::e(status_label($p['status'])) ?></span>
            <span class="market-vis"><?= ($p['visibility'] ?? '') === 'public' ? 'на сайте' : 'видно только соседям' ?></span>
        </div>
        <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?>
            <div class="res-flash res-flash--error">Причина: <?= View::e($p['reject_reason']) ?></div>
        <?php endif; ?>
        <p class="res-meta"><?= View::e(product_price_label($p['price'] ?? null, $p['unit'] ?? null)) ?></p>
        <div class="cab-item-actions">
            <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/<?= (int) $p['id'] ?>/redaktirovat">Изменить</a>
            <form method="post" action="/poselenie/yarmarka/<?= (int) $p['id'] ?>/udalit" data-confirm="Удалить?">
                <?= Csrf::field() ?>
                <button type="submit" class="res-btn res-btn--muted">Удалить</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
