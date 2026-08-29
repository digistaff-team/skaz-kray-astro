<?php
use SkazResidents\{Csrf, View};
use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$label = fn(string $s) => ['available' => 'свободна', 'on_loan' => 'на руках', 'maintenance' => 'недоступна', 'hidden' => 'скрыта'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
$loanLabel = fn(string $s) => ['requested' => 'ожидает решения', 'on_loan' => 'на руках', 'returned' => 'возвращена', 'declined' => 'отклонена', 'cancelled' => 'отменена'][$s] ?? $s;
?>
<p class="res-meta"><a href="/poselenie/knigi">← В каталог</a></p>
<div class="tool-show-head">
    <h1><?= View::e($book['title']) ?></h1>
    <span class="tool-st <?= $cls($book['status']) ?>"><?= $label($book['status']) ?></span>
</div>

<?php if (!empty($images)): ?>
    <div class="tool-gallery">
        <?php foreach ($images as $img): ?>
            <img src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="res-card">
    <?php if ($book['author'] !== ''): ?><p><strong>Автор:</strong> <?= View::e($book['author']) ?></p><?php endif; ?>
    <?php if ($book['genre'] !== ''): ?><p><strong>Жанр:</strong> <?= View::e($book['genre']) ?></p><?php endif; ?>
    <p><strong>Владелец:</strong> <?= View::e($book['owner_name']) ?></p>
    <?php if (!empty($book['condition_note'])): ?><p><strong>Состояние:</strong> <?= View::e($book['condition_note']) ?></p><?php endif; ?>
    <?php if (!empty($book['description'])): ?><p><?= nl2br(View::e($book['description'])) ?></p><?php endif; ?>
    <?php if ($book['status'] === 'on_loan' && $active): ?>
        <p class="res-meta">Сейчас у: <?= View::e($active['borrower_name']) ?><?php if (!empty($active['due_date'])): ?> · до <?= View::e($active['due_date']) ?><?php endif; ?></p>
    <?php endif; ?>
</div>

<?php if ($isOwner): ?>
    <div class="res-card">
        <p class="res-meta">Это ваша книга. Управление — в разделе <a href="/poselenie/knigi/moi">«Мои книги»</a>.</p>
        <p><a class="res-btn res-btn--ghost" href="/poselenie/knigi/<?= (int) $book['id'] ?>/redaktirovat">Редактировать</a></p>
    </div>
    <?php if ($history): ?>
        <div class="res-card">
            <h2>История броней</h2>
            <ul class="tool-history">
                <?php foreach ($history as $h): ?>
                    <li>
                        <span class="tool-loan-st"><?= $loanLabel($h['status']) ?></span>
                        <?= View::e($h['borrower_name']) ?>
                        <span class="res-meta"><?= View::e((string) $h['requested_at']) ?><?php if (!empty($h['return_condition'])): ?> · возврат: <?= $h['return_condition'] === 'broken' ? 'повреждена' : 'в порядке' ?><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php elseif ($canRequest): ?>
    <div class="res-card">
        <h2>Забронировать книгу</h2>
        <form class="res-form" method="post" action="/poselenie/knigi/<?= (int) $book['id'] ?>/bron">
            <?= Csrf::field() ?>
            <label>На какой срок нужна (до какого числа), необязательно
                <input type="date" name="due_date">
            </label>
            <label>Сообщение владельцу (необязательно)
                <textarea name="message" placeholder="Когда удобно забрать, на сколько…"></textarea>
            </label>
            <button class="res-btn" type="submit">Забронировать</button>
        </form>
    </div>
<?php elseif ($book['status'] !== 'available'): ?>
    <div class="res-card"><p class="res-meta">Книга сейчас недоступна для брони (<?= $label($book['status']) ?>).</p></div>
<?php endif; ?>
