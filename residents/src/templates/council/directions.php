<?php use SkazResidents\View; ?>
<h1>Направления работы</h1>
<p class="res-meta">Каждое направление ведёт один член совета. Задачи направления — регулярные и сезонные работы по содержанию Терема.</p>

<div class="sovet-directions">
    <?php foreach ($directions as $dir): ?>
        <div class="res-card sovet-direction">
            <p class="sovet-eyebrow">Ответственный · <?= View::e($dir['lead']) ?></p>
            <h2><?= View::e($dir['title']) ?></h2>
            <ul class="sovet-dir-tasks">
                <?php foreach ($dir['tasks'] as $t): ?>
                    <?php
                        $st = $t['status'];
                        $mark = $st === 'готово' ? '✓' : ($st === 'в работе' ? '◐' : '○');
                    ?>
                    <li class="sovet-dir-task sovet-dir-task--<?= $st === 'готово' ? 'done' : ($st === 'в работе' ? 'progress' : 'plan') ?>">
                        <span class="sovet-dir-mark" title="<?= View::e($st) ?>"><?= $mark ?></span>
                        <span class="sovet-dir-title"><?= View::e($t['title']) ?></span>
                        <span class="sovet-dir-owner"><?= View::e($t['owner']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
