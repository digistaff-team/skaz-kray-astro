<?php
/**
 * Строка закупки для доски и списков «Мои закупки»: заголовок, стадия, прогресс.
 * Ожидает $p — закупку из PurchaseRepository (с collected_qty, percent и т.п.).
 */
use SkazResidents\View;
?>
<a class="res-card buy-card" href="/poselenie/zakupki/<?= (int) $p['id'] ?>">
    <div class="buy-card-head">
        <b class="buy-title"><?= View::e($p['title']) ?></b>
        <span class="tool-st <?= buy_status_class((string) $p['status']) ?>"><?= View::e(buy_status_label((string) $p['status'])) ?></span>
    </div>
    <div class="res-meta">
        <?php if (($p['price_per_unit'] ?? null) !== null): ?>
            <?= View::e(buy_money((string) $p['price_per_unit'])) ?> ₽ за <?= View::e($p['unit']) ?>
        <?php else: ?>
            цена по факту
        <?php endif; ?>
        <?php if (($p['category'] ?? '') !== ''): ?> · <?= View::e($p['category']) ?><?php endif; ?>
        <?php if (($p['deadline'] ?? null) !== null): ?> · до <?= View::e(ru_date((string) $p['deadline'])) ?><?php endif; ?>
    </div>
    <?php require __DIR__ . '/_progress.php'; ?>
    <div class="res-meta">
        <?= (int) $p['participants'] ?> <?= View::e(plural_ru((int) $p['participants'], 'участник', 'участника', 'участников')) ?>
        <?php if (($p['organizer_name'] ?? '') !== ''): ?> · ведёт <?= View::e($p['organizer_name']) ?><?php endif; ?>
        <?php if (($p['my_qty'] ?? null) !== null): ?> · вы берёте <?= View::e(buy_qty((string) $p['my_qty'])) ?> <?= View::e($p['unit']) ?><?php endif; ?>
    </div>
</a>
