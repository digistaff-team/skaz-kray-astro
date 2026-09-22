<?php use SkazResidents\View; ?>
<article class="post">
    <header class="post-head wrap">
        <nav class="crumbs" aria-label="Хлебные крошки">
            <a href="/">Главная</a><span>/</span><a href="/yarmarka/">Ярмарка</a>
        </nav>
        <h1><?= View::e($product['title']) ?></h1>
        <div class="post-meta">
            <span><?= View::e(product_price_label($product['price'], $product['unit'] ?? null)) ?></span>
            <ul class="post-cats"><li><a href="/yarmarka/"><?= View::e($product['family_name']) ?></a></li></ul>
        </div>
    </header>

    <div class="wrap post-body prose">
        <?php foreach ($product['images'] as $img): ?>
            <img src="<?= View::e(entry_image_url($img['path'])) ?>" alt="">
        <?php endforeach; ?>
        <p><?= nl2br(View::e($product['description'])) ?></p>
        <p><strong>Как связаться:</strong> <?= contact_links($product['contact']) ?></p>
    </div>
</article>
