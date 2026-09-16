<?php use SkazResidents\View; ?>
<h1>Доступ для жителей поселения</h1>

<?php if ($reason === 'error'): ?>
    <div class="res-flash res-flash--error">Не удалось проверить членство в группе жителей MAX. Попробуйте позже или обратитесь к администратору поселения.</div>
<?php else: ?>
    <p>Внутренний портал доступен только участникам группы жителей в MAX. Вступите в группу и вернитесь.</p>
<?php endif; ?>

<?php if (!empty($groupLink) && strpos($groupLink, 'CHANGE_ME') === false): ?>
    <p><a class="res-btn" href="<?= View::e($groupLink) ?>" target="_blank" rel="noopener">Вступить в группу жителей</a></p>
<?php endif; ?>
<p><a class="res-btn res-btn--ghost" href="/poselenie/max">Я вступил(а) — проверить снова</a></p>
