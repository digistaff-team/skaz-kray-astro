<?php use SkazResidents\{View, Csrf};
$pages = (int) ceil($total / $perPage);
$mine = $mine ?? [];
?>
<div class="tool-head">
    <h1>Дневник</h1>
    <a class="res-btn" href="/poselenie/dnevnik/novaya">Новая запись</a>
</div>

<?php if ($mine): ?>
    <h2 class="app-sec-label">Мои записи</h2>
    <?php foreach ($mine as $m): ?>
        <div class="res-card cab-item">
            <div class="cab-item-head">
                <strong class="cab-item-title"><?= View::e($m['title']) ?></strong>
                <span class="res-status res-status--<?= View::e($m['status']) ?>"><?= View::e(status_label($m['status'])) ?></span>
            </div>
            <?php if ($m['status'] === 'rejected' && !empty($m['reject_reason'])): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($m['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta"><?= View::e(ru_date((string) ($m['created_at'] ?? ''))) ?></p>
            <div class="cab-item-actions">
                <a class="res-btn res-btn--ghost" href="/poselenie/dnevnik/<?= (int) $m['id'] ?>/redaktirovat">Изменить</a>
                <form method="post" action="/poselenie/dnevnik/<?= (int) $m['id'] ?>/udalit" onsubmit="return confirm('Удалить запись?')">
                    <?= Csrf::field() ?>
                    <button type="submit" class="res-btn res-btn--muted">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2 class="app-sec-label">Дневники поместий</h2>
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
            <img src="<?= View::e(entry_image_url($e['images'][0]['path'])) ?>" alt="">
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
