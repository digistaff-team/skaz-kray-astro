<?php use SkazResidents\{View, Csrf}; /** @var array $products */ ?>
<div class="tool-head">
    <h1>Моя витрина</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka">Товары соседей</a>
    </div>
</div>
<p class="res-meta">Ваши товары и услуги. «Только соседи» публикуются сразу, «На сайте» — после проверки редактором.</p>

<?php if (!$products): ?>
    <p class="res-meta tool-empty">Вы пока ничего не разместили. <a href="/poselenie/yarmarka/novyy">Разместить</a>.</p>
<?php endif; ?>

<?php foreach ($products as $p): ?>
    <div class="res-card cab-item">
        <div class="cab-item-head">
            <strong class="cab-item-title"><?= View::e($p['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($p['status']) ?>"><?= View::e(status_label($p['status'])) ?></span>
            <span class="market-vis"><?= ($p['visibility'] ?? '') === 'public' ? 'на сайте' : 'соседям' ?></span>
        </div>
        <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?>
            <div class="res-flash res-flash--error">Причина: <?= View::e($p['reject_reason']) ?></div>
        <?php endif; ?>
        <p class="res-meta"><?= ($p['price'] ?? '') !== '' ? View::e($p['price']) : 'по договорённости' ?></p>
        <div class="cab-item-actions">
            <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/<?= (int) $p['id'] ?>/redaktirovat">Изменить</a>
            <form method="post" action="/poselenie/yarmarka/<?= (int) $p['id'] ?>/udalit" onsubmit="return confirm('Удалить?')">
                <?= Csrf::field() ?>
                <button type="submit" class="res-btn res-btn--muted">Удалить</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
