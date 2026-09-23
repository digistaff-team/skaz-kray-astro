<?php use SkazResidents\View; /** @var array $products */ ?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<div class="tool-head">
    <h1>Товары и услуги соседей</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/moya">Моя витрина</a>
    </div>
</div>
<p class="res-meta">Что предлагают жители поселения для внутрипоселенческого рынка и на сайте.</p>

<?php if (!$products): ?>
    <p class="res-meta tool-empty">Ваша витрина пока пуста.<br><a href="/poselenie/yarmarka/novyy">Добавьте свои товары и услуги</a></p>
<?php endif; ?>

<div class="market-grid">
    <?php foreach ($products as $p): ?>
        <a class="res-card market-card" href="/poselenie/yarmarka/<?= (int) $p['id'] ?>">
            <?php if (!empty($p['photo'])): ?>
                <img class="market-photo" src="<?= View::e(entry_image_thumb($p['photo'], 240)) ?>" alt="" loading="lazy">
            <?php else: ?>
                <?php /* Товар без фото: нейтральный значок-картинка, чтобы строки не разъезжались. */ ?>
                <span class="market-photo market-photo--none" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"
                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <circle cx="8.5" cy="10" r="1.4"/>
                        <path d="M21 16l-5-5-4.5 5-2-2L3 19"/>
                    </svg>
                </span>
            <?php endif; ?>
            <span class="market-card-body">
                <strong class="market-title"><?= View::e($p['title']) ?></strong>
                <span class="market-price"><?= View::e(product_price_label($p['price'] ?? null, $p['unit'] ?? null)) ?></span>
                <span class="market-meta"><?= View::e($p['family_name']) ?><?php if (($p['visibility'] ?? '') === 'public'): ?> · 🌐 на сайте<?php endif; ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
