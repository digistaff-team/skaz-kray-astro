<?php
use SkazResidents\{Csrf, View};
use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$label = fn(string $s) => ['available' => 'свободен', 'on_loan' => 'на руках', 'maintenance' => 'на обслуживании', 'hidden' => 'скрыт'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
$loanLabel = fn(string $s) => ['requested' => 'ожидает решения', 'on_loan' => 'на руках', 'returned' => 'возвращён', 'declined' => 'отклонён', 'cancelled' => 'отменён'][$s] ?? $s;
?>
<p class="res-meta"><a href="/poselenie/instrumenty">← В каталог</a></p>
<div class="tool-show-head">
    <h1><?= View::e($tool['name']) ?></h1>
    <span class="tool-st <?= $cls($tool['status']) ?>"><?= $label($tool['status']) ?></span>
</div>

<?php if (!empty($images)): ?>
    <div class="tool-gallery">
        <?php foreach ($images as $img): ?>
            <img src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="res-card">
    <?php if ($tool['category'] !== ''): ?><p><strong>Категория:</strong> <?= View::e($tool['category']) ?></p><?php endif; ?>
    <p><strong>Владелец:</strong> <?= View::e($tool['owner_name']) ?></p>
    <?php if (!empty($tool['condition_note'])): ?><p><strong>Состояние:</strong> <?= View::e($tool['condition_note']) ?></p><?php endif; ?>
    <?php if (!empty($tool['terms'])): ?><p><strong>Условия:</strong> <?= View::e($tool['terms']) ?></p><?php endif; ?>
    <?php if (!empty($tool['description'])): ?><p><?= nl2br(View::e($tool['description'])) ?></p><?php endif; ?>
    <?php if ($tool['status'] === 'on_loan' && $active): ?>
        <p class="res-meta">Сейчас у: <?= View::e($active['borrower_name']) ?><?php if (!empty($active['due_date'])): ?> · до <?= View::e($active['due_date']) ?><?php endif; ?></p>
    <?php endif; ?>
</div>

<?php if ($isOwner): ?>
    <div class="res-card">
        <p class="res-meta">Это ваш инструмент. Управление — в разделе <a href="/poselenie/instrumenty/moi">«Мои инструменты»</a>.</p>
        <p><a class="res-btn res-btn--ghost" href="/poselenie/instrumenty/<?= (int) $tool['id'] ?>/redaktirovat">Редактировать</a></p>
    </div>
    <?php if ($history): ?>
        <div class="res-card">
            <h2>История займов</h2>
            <ul class="tool-history">
                <?php foreach ($history as $h): ?>
                    <li>
                        <span class="tool-loan-st"><?= $loanLabel($h['status']) ?></span>
                        <?= View::e($h['borrower_name']) ?>
                        <span class="res-meta"><?= View::e((string) $h['requested_at']) ?><?php if (!empty($h['return_condition'])): ?> · возврат: <?= $h['return_condition'] === 'broken' ? 'неисправен' : 'исправен' ?><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php elseif ($canRequest): ?>
    <div class="res-card">
        <h2>Запросить инструмент</h2>
        <form class="res-form" method="post" action="/poselenie/instrumenty/<?= (int) $tool['id'] ?>/zapros">
            <?= Csrf::field() ?>
            <label>На какой срок нужен (до какого числа), необязательно
                <input type="date" name="due_date">
            </label>
            <label>Сообщение владельцу (необязательно)
                <textarea name="message" placeholder="Для чего нужен, когда удобно забрать…"></textarea>
            </label>
            <button class="res-btn" type="submit">Отправить заявку</button>
        </form>
    </div>
<?php elseif ($tool['status'] !== 'available'): ?>
    <div class="res-card"><p class="res-meta">Инструмент сейчас недоступен для заявки (<?= $label($tool['status']) ?>).</p></div>
<?php endif; ?>
