<?php use SkazResidents\{View}; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<h1>Дневники поместий</h1>
<p class="res-meta">Как живут семьи поселения «Сказочный Край» — записи всех жителей. Часть из них видна и на внешнем сайте (отмечены значком 🌐).</p>
<?php if (!$entries): ?><p>Пока нет опубликованных записей. <a href="/poselenie/dnevnik/novaya">Написать первую?</a></p><?php endif; ?>
<?php foreach ($entries as $e): ?>
    <article class="res-card">
        <h2>
            <a href="/poselenie/dnevniki/<?= (int) $e['id'] ?>"><?= View::e($e['title']) ?></a>
            <?php if (!empty($e['is_public'])): ?><span class="res-status res-status--published" title="Видна на внешнем сайте">🌐 на сайте</span><?php endif; ?>
        </h2>
        <p class="res-meta"><?= View::e($e['family_name']) ?> · <?= View::e(substr((string) $e['published_at'], 0, 10)) ?></p>
        <?php if (!empty($e['images'])): ?>
            <img src="<?= $u ?>/<?= View::e($e['images'][0]['path']) ?>" alt="">
        <?php endif; ?>
        <p><?= View::e(mb_strimwidth(strip_tags((string) $e['body']), 0, 300, '…')) ?></p>
        <a href="/poselenie/dnevniki/<?= (int) $e['id'] ?>">Читать целиком →</a>
    </article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
    <nav class="res-meta">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?><strong><?= $i ?></strong><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
