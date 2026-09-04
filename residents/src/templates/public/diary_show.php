<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
?>
<article class="post">
    <header class="post-head wrap">
        <nav class="crumbs" aria-label="Хлебные крошки">
            <a href="/">Главная</a><span>/</span><a href="/dnevniki-pomestiy/">Дневники поместий</a>
        </nav>
        <h1><?= View::e($entry['title']) ?></h1>
        <div class="post-meta">
            <time><?= View::e(ru_date((string) $entry['published_at'])) ?></time>
            <ul class="post-cats"><li><a href="/dnevniki-pomestiy/"><?= View::e($entry['family_name']) ?></a></li></ul>
        </div>
    </header>

    <div class="wrap post-body prose">
        <?php foreach ($entry['images'] as $img): ?>
            <img src="<?= View::e(entry_image_url($img['path'])) ?>" alt="">
        <?php endforeach; ?>
        <p><?= nl2br(View::e($entry['body'])) ?></p>
    </div>
</article>
