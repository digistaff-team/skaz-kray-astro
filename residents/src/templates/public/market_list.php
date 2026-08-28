<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<h1>Ярмарка</h1>
<p class="res-meta">Товары и услуги семей поселения.</p>
<?php if (!$items): ?><p>Пока нет объявлений.</p><?php endif; ?>
<?php foreach ($items as $p): ?>
    <article class="res-card">
        <h2><a href="/yarmarka/<?= (int) $p['id'] ?>"><?= View::e($p['title']) ?></a></h2>
        <?php if (!empty($p['images'])): ?>
            <img src="<?= $u ?>/<?= View::e($p['images'][0]['path']) ?>" alt="">
        <?php endif; ?>
        <p><?= View::e(mb_strimwidth((string) $p['description'], 0, 240, '…')) ?></p>
        <p class="res-meta">
            <?= $p['price'] !== null ? View::e($p['price']) : 'по договорённости' ?>
            · <?= View::e($p['family_name']) ?>
        </p>
    </article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
    <nav class="res-meta">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?><strong><?= $i ?></strong><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
