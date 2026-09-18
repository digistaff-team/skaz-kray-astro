<?php
use SkazResidents\View;
/**
 * Содержимое модального окна «Уровень воды в Шебше». Отдаётся отдельным
 * фрагментом (WaterLevelController::panel) и вставляется в оверлей при первом
 * открытии. Главная цифра — запас до нижней кромки моста.
 * @var array $water данные из SkazResidents\Service\WaterLevel::panel()
 */
?>
<?php if ($water['level'] === null): ?>
    <p class="water-empty">Замер пока не получен: гидропост не ответил. Попробуйте позже.</p>
<?php else: ?>
    <div class="water-gap water-gap--<?= View::e($water['status']) ?>">
        <b><?= View::e($water['gapLabel']) ?></b>
        <span><?= $water['flooded'] ? 'выше нижней кромки моста' : 'до нижней кромки моста' ?></span>
    </div>
    <p class="water-meta">
        Уровень <?= View::e($water['levelLabel']) ?> БСВ · <?= View::e($water['changeLabel']) ?><br>
        Прошлый замер был в <?= View::e($water['measuredAt']) ?>
    </p>

    <?php if ($water['chart']): $c = $water['chart']; ?>
        <svg class="water-chart" viewBox="0 0 <?= (int) $c['w'] ?> <?= (int) $c['h'] ?>" role="img"
             aria-label="Уровень воды за последние <?= (int) $c['days'] ?> суток">
            <polygon class="water-chart-area" points="<?= View::e($c['area']) ?>"/>
            <polyline class="water-chart-line" points="<?= View::e($c['poly']) ?>"/>
            <?php foreach ($c['marks'] as $m): ?>
                <line class="water-chart-mark" x1="0" y1="<?= $m['y'] ?>" x2="<?= (int) $c['w'] ?>" y2="<?= $m['y'] ?>"/>
                <text class="water-chart-mark-label" x="2" y="<?= $m['y'] - 3 ?>"><?= View::e($m['label']) ?></text>
            <?php endforeach; ?>
        </svg>
        <div class="water-axis">
            <span><?= View::e($c['firstDate']) ?></span>
            <span><?= View::e($c['rangeLabel']) ?></span>
            <span><?= View::e($c['lastDate']) ?></span>
        </div>
    <?php else: ?>
        <p class="water-meta">История пока копится — диаграмма появится через сутки наблюдений.</p>
    <?php endif; ?>

    <p class="water-note">
        Данные гидропоста на мосту, замер каждый час
        <a href="https://shebsh-water-level.vercel.app" target="_blank" rel="noopener">Подробный график</a>
    </p>
<?php endif; ?>
