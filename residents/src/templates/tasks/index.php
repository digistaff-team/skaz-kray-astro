<?php use SkazResidents\View; /** @var array<int,array<string,mixed>> $tasks */ ?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Мои дела</h1>
<?php if (!$tasks): ?>
    <p class="res-meta tool-empty">Все дела сделаны. <a href="/poselenie/app">На главную</a></p>
<?php else: ?>
    <p class="res-meta">То, что ждёт вашего ответа. Сделаете — пункт исчезнет сам.</p>
    <ul class="task-list">
        <?php foreach ($tasks as $t): ?>
            <li>
                <a class="task-item<?= $t['urgent'] ? ' task-item--urgent' : '' ?>" href="<?= View::e($t['link']) ?>">
                    <b><?= View::e($t['title']) ?></b>
                    <?php if ($t['detail'] !== ''): ?><span><?= View::e($t['detail']) ?></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
