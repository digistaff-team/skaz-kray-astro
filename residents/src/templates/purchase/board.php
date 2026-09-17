<?php
use SkazResidents\View;
/** @var array $purchases @var array $categories @var string $q @var string $category @var string $status */
?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<div class="tool-head">
    <h1>Закупки поселения</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/zakupki/novaya">+ Открыть закупку</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/zakupki/moi">Мои закупки</a>
    </div>
</div>
<p class="res-meta">Оптовая цена начинается с объёма, который одной семье не нужен. Здесь соседи складываются в один заказ: смотрите, что собирают, и записывайтесь своим количеством.</p>

<form class="tool-filters" method="get" action="/poselenie/zakupki">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Товар, категория или поставщик">
    <select name="category">
        <option value="">Все категории</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= View::e($c) ?>"<?= $category === $c ? ' selected' : '' ?>><?= View::e($c) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Любая стадия</option>
        <option value="collecting"<?= $status === 'collecting' ? ' selected' : '' ?>>идёт сбор</option>
        <option value="ordered"<?= $status === 'ordered' ? ' selected' : '' ?>>заказано</option>
        <option value="arrived"<?= $status === 'arrived' ? ' selected' : '' ?>>привезли</option>
        <option value="done"<?= $status === 'done' ? ' selected' : '' ?>>завершена</option>
    </select>
    <button class="res-btn" type="submit">Найти</button>
</form>

<?php if (!$purchases): ?>
    <?php if ($q !== '' || $category !== '' || $status !== ''): ?>
        <p class="res-meta tool-empty">Ничего не нашлось. Попробуйте изменить параметры поиска.</p>
    <?php else: ?>
        <p class="res-meta tool-empty">Пока никто ничего не собирает. Будьте первым — <a href="/poselenie/zakupki/novaya">откройте закупку</a>.</p>
    <?php endif; ?>
<?php endif; ?>

<div class="buy-list">
    <?php foreach ($purchases as $p): ?>
        <?php require __DIR__ . '/_card.php'; ?>
    <?php endforeach; ?>
</div>
