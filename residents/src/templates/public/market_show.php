<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
?>
<div class="wrap" style="padding-top:2.6rem;padding-bottom:3rem;max-width:820px;">
<article>
    <h1><?= View::e($product['title']) ?></h1>
    <p class="res-meta"><?= View::e($product['family_name']) ?></p>
    <?php foreach ($product['images'] as $img): ?>
        <img class="res-card" src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
    <?php endforeach; ?>
    <div><?= nl2br(View::e($product['description'])) ?></div>
    <p><strong>Цена:</strong> <?= $product['price'] !== null ? View::e($product['price']) : 'по договорённости' ?></p>
    <p><strong>Как связаться:</strong> <?= View::e($product['contact']) ?></p>
    <p><a href="/yarmarka/">← Вся Ярмарка</a></p>
</article>
</div>
