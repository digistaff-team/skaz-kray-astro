<?php
use SkazResidents\View;
use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<div class="cat">
    <header class="cat-head">
        <div class="wrap">
            <nav class="crumbs" aria-label="Хлебные крошки">
                <a href="/">Главная</a><span>/</span><a href="/category/stati/">Статьи</a>
            </nav>
            <p class="eyebrow">Рубрика</p>
            <h1>Дневники поместий</h1>
        </div>
    </header>

    <div class="wrap cat-body">
        <?php if (!$entries): ?>
            <p class="empty">В этой рубрике пока нет записей.</p>
        <?php else: ?>
            <div class="cat-grid">
                <?php foreach ($entries as $e): $id = (int) $e['id']; ?>
                    <article class="pcard">
                        <?php if (!empty($e['images'])): ?>
                            <a href="/dnevniki-pomestiy/<?= $id ?>" class="pcard-media" tabindex="-1" aria-hidden="true">
                                <img src="<?= View::e(entry_image_url($e['images'][0]['path'])) ?>" alt="" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="pcard-body">
                            <time class="pcard-date"><?= View::e(ru_date((string) $e['published_at'])) ?></time>
                            <h3 class="pcard-title"><a href="/dnevniki-pomestiy/<?= $id ?>"><?= View::e($e['title']) ?></a></h3>
                            <p class="pcard-excerpt"><?= View::e(mb_strimwidth(strip_tags((string) $e['body']), 0, 160, '…')) ?></p>
                            <a href="/dnevniki-pomestiy/<?= $id ?>" class="pcard-more">Читать дневник →</a>
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

        <p class="archive-note">Записи прошлых лет, опубликованные до запуска этой ленты — <a href="/category/dnevniki-pomestij/">архив дневников →</a></p>
    </div>
</div>
