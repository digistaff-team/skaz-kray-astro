<?php
use SkazResidents\{Csrf, View};
$kind = ($d['kind'] ?? 'buy') === 'pickup' ? 'pickup' : 'buy';
$v = static fn(string $k): string => View::e((string) ($d[$k] ?? ''));
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="res-flash res-flash--error">' . View::e($errors[$k]) . '</div>' : '';
?>
<?php $backFallback = $trip ? '/poselenie/poezdki/' . (int) $trip['id'] : '/poselenie/dostavka'; require __DIR__ . '/../partials/back.php'; ?>
<?php $tab = 'dostavka'; require __DIR__ . '/../partials/trip-tabs.php'; ?>
<h1>Попросить привезти</h1>
<?php if ($trip): ?>
    <div class="res-card">
        <p><strong>Просьба к поездке</strong> <?= View::e($trip['origin']) ?> → <?= View::e($trip['destination']) ?></p>
        <p class="res-meta"><?= View::e(ru_date((string) $trip['trip_date'])) ?><?php if (!empty($trip['trip_time'])): ?>, <?= View::e($trip['trip_time']) ?><?php endif; ?> · водитель <?= View::e($trip['driver_name']) ?></p>
    </div>
<?php endif; ?>

<form class="res-form" method="post" action="/poselenie/dostavka/novaya" id="deliveryForm">
    <?= Csrf::field() ?>
    <?php if ($trip): ?><input type="hidden" name="trip_id" value="<?= (int) $trip['id'] ?>"><?php endif; ?>
    <fieldset class="delivery-kind">
        <legend>Тип</legend>
        <label class="res-checkbox"><input type="radio" name="kind" value="buy"<?= $kind === 'buy' ? ' checked' : '' ?>> Купить</label>
        <label class="res-checkbox"><input type="radio" name="kind" value="pickup"<?= $kind === 'pickup' ? ' checked' : '' ?>> Забрать</label>
    </fieldset>

    <label><span class="js-kind-buy">Что купить</span><span class="js-kind-pickup">Что забрать</span>
        <textarea name="what" maxlength="1000" required><?= $v('what') ?></textarea>
    </label>
    <?= $err('what') ?>
    <label>Где (магазин, аптека, пункт выдачи, почта)
        <input type="text" name="place" maxlength="160" value="<?= $v('place') ?>" required>
    </label>
    <?= $err('place') ?>
    <label>К какому дню (необязательно)
        <input type="date" name="need_by" value="<?= $v('need_by') ?>">
    </label>
    <?= $err('need_by') ?>
    <label class="js-kind-buy">Примерная сумма, ₽ (необязательно)
        <input type="text" name="budget" inputmode="decimal" value="<?= ($d['budget'] ?? null) !== null ? View::e(buy_money((string) $d['budget'])) : '' ?>">
    </label>
    <?= $err('budget') ?>
    <label class="js-kind-pickup">Код или номер заказа (необязательно)
        <input type="text" name="pickup_code" maxlength="200" value="<?= $v('pickup_code') ?>">
        <span class="res-meta">Увидит только тот, кто возьмёт заявку.</span>
    </label>
    <?= $err('pickup_code') ?>
    <label>Комментарий (необязательно)
        <input type="text" name="note" maxlength="500" value="<?= $v('note') ?>">
    </label>
    <?= $err('note') ?>
    <button class="res-btn" type="submit">Попросить привезти</button>
</form>

<script>
// Поля по типу: «Купить» — сумма, «Забрать» — код. Без скрипта видны все, сервер лишнее игнорирует.
(function () {
  var form = document.getElementById('deliveryForm');
  if (!form) { return; }
  function sync() {
    var kind = form.querySelector('input[name="kind"]:checked').value;
    Array.prototype.forEach.call(form.querySelectorAll('.js-kind-buy'), function (el) { el.hidden = kind !== 'buy'; });
    Array.prototype.forEach.call(form.querySelectorAll('.js-kind-pickup'), function (el) { el.hidden = kind !== 'pickup'; });
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[name="kind"]'), function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
