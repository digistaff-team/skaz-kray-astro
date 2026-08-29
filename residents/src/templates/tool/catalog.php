<?php
use SkazResidents\View;
use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$label = fn(string $s) => ['available' => 'свободен', 'on_loan' => 'на руках', 'maintenance' => 'на обслуживании', 'hidden' => 'скрыт'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
?>
<div class="tool-head">
    <h1>Инструменты поселения</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/instrumenty/novyy">+ Поделиться инструментом</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/instrumenty/moi">Мои инструменты</a>
    </div>
</div>
<p class="res-meta">Общая копилка инструментов жителей: возьмите нужное у соседа или поделитесь своим. Заявку на инструмент одобряет его владелец.</p>

<form class="tool-filters" method="get" action="/poselenie/instrumenty">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Поиск по названию или категории">
    <select name="category">
        <option value="">Все категории</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= View::e($c) ?>"<?= $category === $c ? ' selected' : '' ?>><?= View::e($c) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Любой статус</option>
        <option value="available"<?= $status === 'available' ? ' selected' : '' ?>>свободен</option>
        <option value="on_loan"<?= $status === 'on_loan' ? ' selected' : '' ?>>на руках</option>
        <option value="maintenance"<?= $status === 'maintenance' ? ' selected' : '' ?>>на обслуживании</option>
    </select>
    <button class="res-btn" type="submit">Найти</button>
</form>

<?php if (!$tools): ?>
    <p class="res-meta tool-empty">Ничего не найдено. Будьте первым — <a href="/poselenie/instrumenty/novyy">поделитесь инструментом</a>.</p>
<?php endif; ?>

<div class="tool-grid">
    <?php foreach ($tools as $t): ?>
        <a class="tool-card" href="/poselenie/instrumenty/<?= (int) $t['id'] ?>">
            <div class="tool-thumb">
                <?php if (!empty($t['photo'])): ?>
                    <img src="<?= $u ?>/<?= View::e($t['photo']) ?>" alt="">
                <?php else: ?>
                    <span class="tool-thumb-empty">🔧</span>
                <?php endif; ?>
                <span class="tool-st <?= $cls($t['status']) ?>"><?= $label($t['status']) ?></span>
            </div>
            <div class="tool-card-body">
                <strong class="tool-card-name"><?= View::e($t['name']) ?></strong>
                <?php if ($t['category'] !== ''): ?><span class="tool-cat"><?= View::e($t['category']) ?></span><?php endif; ?>
                <span class="res-meta">Владелец: <?= View::e($t['owner_name']) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</div>
