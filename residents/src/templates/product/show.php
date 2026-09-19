<?php use SkazResidents\View; /** @var array $product @var array $images @var bool $isOwner */ ?>
<?php $backFallback = '/poselenie/yarmarka'; require __DIR__ . '/../partials/back.php'; ?>
<h1><?= View::e($product['title']) ?></h1>
<p class="market-price market-price--big"><?= View::e(product_price_label($product['price'] ?? null, $product['unit'] ?? null)) ?></p>

<?php if ($images): ?>
    <div class="market-gallery">
        <?php foreach ($images as $img): ?>
            <img src="<?= View::e(entry_image_thumb($img['path'], 480)) ?>" alt="" loading="lazy">
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="res-card">
    <p><?= nl2br(View::e((string) $product['description'])) ?></p>
    <p><strong>Как связаться:</strong> <?= contact_links((string) $product['contact']) ?></p>
    <?php $phone = contact_phone((string) $product['contact']); $tg = contact_telegram((string) $product['contact']); ?>
    <?php if ($phone !== null || $tg !== null): ?>
        <p class="market-actions">
            <?php if ($phone !== null): ?>
                <a class="res-btn" href="tel:<?= View::e($phone) ?>">Позвонить</a>
            <?php endif; ?>
            <?php if ($tg !== null): ?>
                <a class="res-btn res-btn--ghost js-tg-link" href="https://t.me/<?= View::e($tg) ?>" target="_blank" rel="noopener">Написать</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <p class="market-meta"><?= View::e((string) $product['family_name']) ?><?php if (($product['visibility'] ?? '') === 'public'): ?> · 🌐 на сайте<?php endif; ?></p>
</div>

<?php if ($isOwner): ?>
    <p>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/<?= (int) $product['id'] ?>/redaktirovat">Изменить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/moya">Моя витрина</a>
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../partials/tg-links.php'; ?>
