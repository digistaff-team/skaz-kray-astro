<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<div class="cat">
    <header class="cat-head">
        <div class="wrap">
            <nav class="crumbs" aria-label="Хлебные крошки">
                <a href="/">Главная</a>
            </nav>
            <p class="eyebrow">Раздел</p>
            <h1>Ярмарка</h1>
        </div>
    </header>

    <div class="wrap cat-body">
        <?php if (!$items): ?>
            <p class="empty">Пока нет объявлений.</p>
        <?php else: ?>
            <div class="cat-grid">
                <?php foreach ($items as $p): $id = (int) $p['id']; ?>
                    <article class="pcard">
                        <?php if (!empty($p['images'])): ?>
                            <a href="/yarmarka/<?= $id ?>" class="pcard-media" tabindex="-1" aria-hidden="true">
                                <img src="<?= $u ?>/<?= View::e($p['images'][0]['path']) ?>" alt="" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="pcard-body">
                            <time class="pcard-date"><?= $p['price'] !== null ? View::e($p['price']) : 'по договорённости' ?></time>
                            <h3 class="pcard-title"><a href="/yarmarka/<?= $id ?>"><?= View::e($p['title']) ?></a></h3>
                            <p class="pcard-excerpt"><?= View::e(mb_strimwidth((string) $p['description'], 0, 160, '…')) ?></p>
                            <a href="/yarmarka/<?= $id ?>" class="pcard-more"><?= View::e($p['family_name']) ?> →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Страницы">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <?php if ($i === $page): ?><strong><?= $i ?></strong><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>
