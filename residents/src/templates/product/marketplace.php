<?php use SkazResidents\View; /** @var array $products */ ?>
<a class="res-back" href="/poselenie/app">← На главную</a>
<div class="tool-head">
    <h1>Товары и услуги соседей</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/moya">Моя витрина</a>
    </div>
</div>
<p class="res-meta">Что предлагают жители поселения для внутрипоселенческого рынка и на сайте.</p>

<?php if (!$products): ?>
    <p class="res-meta tool-empty">Ваша витрина пока пуста. <a href="/poselenie/yarmarka/novyy">Добавьте свои товары и услуги</a></p>
<?php endif; ?>

<div class="market-grid">
    <?php foreach ($products as $p): ?>
        <a class="res-card market-card" href="/poselenie/yarmarka/<?= (int) $p['id'] ?>">
            <?php if (!empty($p['photo'])): ?>
                <img class="market-photo" src="<?= View::e(entry_image_thumb($p['photo'], 240)) ?>" alt="" loading="lazy">
            <?php else: ?>
                <span class="market-photo market-photo--none" aria-hidden="true">🛒</span>
            <?php endif; ?>
            <span class="market-card-body">
                <strong class="market-title"><?= View::e($p['title']) ?></strong>
                <span class="market-price"><?= View::e(product_price_label($p['price'] ?? null, $p['unit'] ?? null)) ?></span>
                <span class="market-meta"><?= View::e($p['family_name']) ?><?php if (($p['visibility'] ?? '') === 'public'): ?> · 🌐 на сайте<?php endif; ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
