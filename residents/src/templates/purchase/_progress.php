<?php
/**
 * Полоса «собрано столько-то из цели». Без цели полосы нет — показываем просто
 * набранный объём, чтобы не рисовать прогресс к неизвестному.
 * Ожидает $p — закупку из PurchaseRepository.
 */
use SkazResidents\View;
?>
<?php if (($p['percent'] ?? null) !== null): ?>
    <div class="buy-bar" role="img" aria-label="Собрано <?= (int) $p['percent'] ?>% от цели">
        <span class="buy-bar-fill" style="width: <?= (int) $p['percent'] ?>%"></span>
    </div>
    <div class="buy-progress-text">
        собрано <?= View::e(buy_qty((string) $p['collected_qty'])) ?> из <?= View::e(buy_qty((string) $p['target_qty'])) ?> <?= View::e($p['unit']) ?>
        <span class="buy-percent"><?= (int) $p['percent'] ?>%</span>
    </div>
<?php else: ?>
    <div class="buy-progress-text">
        собрано <?= View::e(buy_qty((string) $p['collected_qty'])) ?> <?= View::e($p['unit']) ?>
    </div>
<?php endif; ?>
