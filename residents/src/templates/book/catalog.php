<?php
use SkazResidents\View;
use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$label = fn(string $s) => ['available' => 'свободна', 'on_loan' => 'на руках', 'maintenance' => 'недоступна', 'hidden' => 'скрыта'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
?>
<div class="tool-head">
    <h1>Книги поселения</h1>
    <div class="tool-head-actions">
        <a class="res-btn" href="/poselenie/knigi/novaya">+ Поделиться книгой</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/knigi/moi">Мои книги</a>
    </div>
</div>
<p class="res-meta">Общая книжная полка жителей: возьмите книгу почитать у соседа или поделитесь своей. Бронь подтверждает владелец, после прочтения книга возвращается ему.</p>

<form class="tool-filters" method="get" action="/poselenie/knigi">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Поиск по названию, автору или жанру">
    <select name="genre">
        <option value="">Все жанры</option>
        <?php foreach ($genres as $g): ?>
            <option value="<?= View::e($g) ?>"<?= $genre === $g ? ' selected' : '' ?>><?= View::e($g) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Любой статус</option>
        <option value="available"<?= $status === 'available' ? ' selected' : '' ?>>свободна</option>
        <option value="on_loan"<?= $status === 'on_loan' ? ' selected' : '' ?>>на руках</option>
        <option value="maintenance"<?= $status === 'maintenance' ? ' selected' : '' ?>>недоступна</option>
    </select>
    <button class="res-btn" type="submit">Найти</button>
</form>

<?php if (!$books): ?>
    <p class="res-meta tool-empty">Ничего не найдено. Будьте первым — <a href="/poselenie/knigi/novaya">поделитесь книгой</a>.</p>
<?php endif; ?>

<div class="tool-grid">
    <?php foreach ($books as $b): ?>
        <a class="tool-card" href="/poselenie/knigi/<?= (int) $b['id'] ?>">
            <div class="tool-thumb">
                <?php if (!empty($b['photo'])): ?>
                    <img src="<?= $u ?>/<?= View::e($b['photo']) ?>" alt="">
                <?php else: ?>
                    <span class="tool-thumb-empty">📖</span>
                <?php endif; ?>
                <span class="tool-st <?= $cls($b['status']) ?>"><?= $label($b['status']) ?></span>
            </div>
            <div class="tool-card-body">
                <strong class="tool-card-name"><?= View::e($b['title']) ?></strong>
                <?php if ($b['author'] !== ''): ?><span class="res-meta"><?= View::e($b['author']) ?></span><?php endif; ?>
                <?php if ($b['genre'] !== ''): ?><span class="tool-cat"><?= View::e($b['genre']) ?></span><?php endif; ?>
                <span class="res-meta">Владелец: <?= View::e($b['owner_name']) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</div>
