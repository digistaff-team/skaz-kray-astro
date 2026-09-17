<?php
use SkazResidents\{Csrf, View};
/** @var array $purchase @var array $images @var array $orders @var ?array $myOrder @var bool $isOwner */
$p = $purchase;
$open = $p['status'] === 'collecting';
?>
<?php $backFallback = '/poselenie/zakupki'; require __DIR__ . '/../partials/back.php'; ?>
<div class="tool-show-head">
    <h1><?= View::e($p['title']) ?></h1>
    <span class="tool-st <?= buy_status_class((string) $p['status']) ?>"><?= View::e(buy_status_label((string) $p['status'])) ?></span>
</div>
<p class="res-meta">
    Ведёт <?= View::e($p['organizer_name']) ?>
    <?php if (($p['category'] ?? '') !== ''): ?> · <?= View::e($p['category']) ?><?php endif; ?>
    <?php if (($p['deadline'] ?? null) !== null): ?> · сбор до <?= View::e(ru_date((string) $p['deadline'])) ?><?php endif; ?>
</p>

<div class="res-card">
    <?php if (($p['price_per_unit'] ?? null) !== null): ?>
        <p class="buy-price"><b><?= View::e(buy_money((string) $p['price_per_unit'])) ?> ₽</b> за <?= View::e($p['unit']) ?></p>
    <?php else: ?>
        <p class="res-meta">Цена за <?= View::e($p['unit']) ?> уточняется.</p>
    <?php endif; ?>
    <?php require __DIR__ . '/_progress.php'; ?>
    <?php if (($p['total_sum'] ?? null) !== null): ?>
        <p class="res-meta">
            Сумма заказа: <?= View::e(buy_money((string) $p['total_sum'])) ?> ₽,
            оплачено <?= View::e(buy_money((string) $p['paid_sum'])) ?> ₽
        </p>
    <?php endif; ?>
    <?php if (($p['supplier'] ?? '') !== ''): ?><p class="res-meta">Поставщик: <?= View::e($p['supplier']) ?></p><?php endif; ?>
    <?php if (($p['pickup'] ?? '') !== ''): ?><p class="res-meta">Где забирать: <?= View::e($p['pickup']) ?></p><?php endif; ?>
    <?php if (($p['note'] ?? '') !== ''): ?><p><?= nl2br(View::e($p['note'])) ?></p><?php endif; ?>
</div>

<?php if ($images): ?>
    <div class="tool-gallery">
        <?php foreach ($images as $img): ?>
            <img class="photo-thumb js-photo-full" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>"
                 data-full="<?= View::e(entry_image_url($img['path'])) ?>" alt="" loading="lazy">
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$isOwner): ?>
    <section>
        <h2><?= $myOrder ? 'Ваша заявка' : 'Участвовать' ?></h2>
        <?php if ($open): ?>
            <form class="res-form buy-join" method="post" action="/poselenie/zakupki/<?= (int) $p['id'] ?>/uchastvovat">
                <?= Csrf::field() ?>
                <label>Сколько берёте, <?= View::e($p['unit']) ?>
                    <input type="text" name="qty" inputmode="decimal" value="<?= View::e($myOrder ? buy_qty((string) $myOrder['qty']) : '') ?>" required>
                </label>
                <label>Комментарий (необязательно)
                    <input type="text" name="note" maxlength="500" value="<?= View::e($myOrder['note'] ?? '') ?>">
                </label>
                <button class="res-btn" type="submit"><?= $myOrder ? 'Изменить заявку' : 'Записаться' ?></button>
            </form>
            <?php if ($myOrder): ?>
                <form method="post" action="/poselenie/zakupki/<?= (int) $p['id'] ?>/otkazatsya" class="buy-inline-form">
                    <?= Csrf::field() ?>
                    <button class="res-btn res-btn--ghost" type="submit">Выйти из закупки</button>
                </form>
            <?php endif; ?>
        <?php elseif ($myOrder): ?>
            <p class="res-meta">Вы берёте <?= View::e(buy_qty((string) $myOrder['qty'])) ?> <?= View::e($p['unit']) ?><?php if (($p['price_per_unit'] ?? null) !== null): ?> на <?= View::e(buy_money((string) ((float) $myOrder['qty'] * (float) $p['price_per_unit']))) ?> ₽<?php endif; ?>. Сбор закрыт, состав передан поставщику.</p>
        <?php else: ?>
            <p class="res-meta">Сбор закрыт — записаться в эту закупку уже нельзя.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section>
    <h2>Участники <span class="res-meta">(<?= count($orders) ?>)</span></h2>
    <?php if (!$orders): ?><p class="res-meta">Пока никто не записался.</p><?php endif; ?>
    <?php foreach ($orders as $o): ?>
        <div class="res-card buy-row">
            <div>
                <b><?= View::e($o['family_name']) ?></b>
                <span class="res-meta">
                    <?= View::e(buy_qty((string) $o['qty'])) ?> <?= View::e($p['unit']) ?>
                    <?php if (($p['price_per_unit'] ?? null) !== null): ?>
                        · <?= View::e(buy_money((string) ((float) $o['qty'] * (float) $p['price_per_unit']))) ?> ₽
                    <?php endif; ?>
                    <?php if (($o['note'] ?? '') !== ''): ?> · <?= View::e($o['note']) ?><?php endif; ?>
                </span>
            </div>
            <div class="buy-row-actions">
                <?php if (($o['paid_at'] ?? null) !== null): ?>
                    <span class="tool-st tool-st--free">оплачено</span>
                <?php elseif ($isOwner): ?>
                    <span class="res-meta">не оплачено</span>
                <?php endif; ?>
                <?php if ($isOwner): ?>
                    <form method="post" action="/poselenie/zakupki/<?= (int) $p['id'] ?>/oplata/<?= (int) $o['id'] ?>" class="buy-inline-form">
                        <?= Csrf::field() ?>
                        <button class="res-btn res-btn--ghost" type="submit"><?= ($o['paid_at'] ?? null) !== null ? 'Снять отметку' : 'Отметить оплату' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($isOwner): ?>
    <section>
        <h2>Управление закупкой</h2>
        <p class="res-meta">Стадию переключаете вы — участники получат уведомление о заказе, о привозе и об отмене.</p>
        <div class="buy-stage-actions">
            <?php
            // Показываем только осмысленные переходы из текущей стадии.
            $next = match ($p['status']) {
                'collecting' => [['ordered', 'Заказано у поставщика'], ['cancelled', 'Отменить закупку']],
                'ordered'    => [['arrived', 'Привезли'], ['collecting', 'Вернуть в сбор'], ['cancelled', 'Отменить закупку']],
                'arrived'    => [['done', 'Все забрали']],
                default      => [['collecting', 'Открыть сбор снова']],
            };
            ?>
            <?php foreach ($next as [$code, $label]): ?>
                <form method="post" action="/poselenie/zakupki/<?= (int) $p['id'] ?>/stadiya" class="buy-inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="status" value="<?= View::e($code) ?>">
                    <button class="res-btn<?= $code === 'cancelled' ? ' res-btn--ghost' : '' ?>" type="submit"><?= View::e($label) ?></button>
                </form>
            <?php endforeach; ?>
            <a class="res-btn res-btn--ghost" href="/poselenie/zakupki/<?= (int) $p['id'] ?>/redaktirovat">Редактировать</a>
            <form method="post" action="/poselenie/zakupki/<?= (int) $p['id'] ?>/udalit" class="buy-inline-form">
                <?= Csrf::field() ?>
                <button class="res-btn res-btn--ghost" type="submit">Удалить</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<div class="res-lightbox" id="buyLightbox" hidden>
    <img class="res-lightbox-img" src="" alt="">
</div>
<script>
(function () {
  var lb = document.getElementById('buyLightbox');
  var img = lb && lb.querySelector('.res-lightbox-img');
  if (!lb || !img) { return; }
  Array.prototype.forEach.call(document.querySelectorAll('.js-photo-full'), function (t) {
    t.addEventListener('click', function () { img.src = t.getAttribute('data-full') || t.src; lb.hidden = false; });
  });
  lb.addEventListener('click', function () { lb.hidden = true; img.src = ''; });
})();
</script>
