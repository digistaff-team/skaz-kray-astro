<?php use SkazResidents\View; /** @var array $products */ ?>
<div class="tool-head">
    <h1>Товары соседей</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/moya">Моя витрина</a>
    </div>
</div>
<p class="res-meta">Что предлагают жители поселения — товары и услуги внутрипоселенческого рынка и с сайта.</p>

<?php if (!$products): ?>
    <p class="res-meta tool-empty">Пока никто ничего не разместил. <a href="/poselenie/yarmarka/novyy">Разместите первым</a>.</p>
<?php endif; ?>

<div class="market-grid">
    <?php foreach ($products as $p): ?>
        <div class="res-card market-card">
            <?php if (!empty($p['photo'])): ?>
                <img class="market-photo" src="<?= View::e(entry_image_url($p['photo'])) ?>" alt="">
            <?php endif; ?>
            <strong class="market-title"><?= View::e($p['title']) ?></strong>
            <span class="market-price"><?= ($p['price'] ?? '') !== '' ? View::e($p['price']) : 'по договорённости' ?></span>
            <p class="res-meta"><?= View::e(mb_strimwidth(strip_tags((string) $p['description']), 0, 160, '…')) ?></p>
            <p class="market-meta"><?= View::e($p['family_name']) ?> · <?= View::e($p['contact']) ?><?php if (($p['visibility'] ?? '') === 'public'): ?> · 🌐 на сайте<?php endif; ?></p>
        </div>
    <?php endforeach; ?>
</div>
