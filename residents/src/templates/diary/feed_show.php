<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
?>
<article>
    <h1>
        <?= View::e($entry['title']) ?>
        <?php if (!empty($entry['is_public'])): ?><span class="res-status res-status--published" title="Видна на внешнем сайте">🌐 на сайте</span><?php endif; ?>
    </h1>
    <p class="res-meta"><?= View::e($entry['family_name']) ?> · <?= View::e(substr((string) $entry['published_at'], 0, 10)) ?></p>
    <?php foreach ($entry['images'] as $img): ?>
        <img class="res-card" src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
    <?php endforeach; ?>
    <div><?= nl2br(View::e($entry['body'])) ?></div>
    <p><a href="/poselenie/dnevniki">← Ко всем дневникам</a></p>
</article>
