<?php
use SkazResidents\{Auth, Csrf, View};
use SkazResidents\Service\DeliveryPolicy as P;
$id = (int) $d['id'];
$isRequester = (int) $d['requester_id'] === Auth::id();
$has = static fn(string $a): bool => in_array($a, $actions, true);
$room = max(0, P::MAX_RECEIPTS - count($receipts));   // сколько ещё фото чека можно приложить
$post = static function (string $slug, string $label, string $cls = 'res-btn') use ($id): string {
    return '<form method="post" action="/poselenie/dostavka/' . $id . '/' . $slug . '" class="buy-inline-form">'
        . Csrf::field() . '<button class="' . $cls . '" type="submit">' . View::e($label) . '</button></form>';
};
?>
<?php $backFallback = '/poselenie/dostavka'; require __DIR__ . '/../partials/back.php'; ?>
<?php $tab = 'dostavka'; require __DIR__ . '/../partials/trip-tabs.php'; ?>
<div class="tool-show-head">
    <h1><?= View::e(delivery_kind_label((string) $d['kind'])) ?>: <?= View::e($d['place']) ?></h1>
    <span class="tool-st <?= delivery_status_class((string) $d['status']) ?>"><?= View::e(delivery_status_label((string) $d['status'])) ?></span>
</div>

<div class="res-card">
    <p><?= nl2br(View::e((string) $d['what'])) ?></p>
    <p class="res-meta">Просит <?= View::e($d['req_name']) ?><?php if (($d['need_by'] ?? '') !== ''): ?> · к <?= View::e(ru_date((string) $d['need_by'])) ?><?php endif; ?></p>
    <?php if ($d['kind'] === 'buy' && ($d['budget'] ?? null) !== null): ?><p class="res-meta">Примерная сумма: <?= View::e(buy_money((string) $d['budget'])) ?> ₽</p><?php endif; ?>
    <?php if (($d['note'] ?? '') !== ''): ?><p class="res-meta">Комментарий: <?= View::e($d['note']) ?></p><?php endif; ?>
    <?php if ($d['trip_id'] !== null && ($d['origin'] ?? null) !== null): ?>
        <p class="res-meta">Поездка: <a href="/poselenie/poezdki/<?= (int) $d['trip_id'] ?>"><?= View::e($d['origin']) ?> → <?= View::e($d['destination']) ?></a>, <?= View::e(ru_date((string) $d['trip_date'])) ?> · водитель <?= View::e($d['driver_name']) ?></p>
    <?php endif; ?>
    <?php if ($d['carrier_id'] !== null): ?><p class="res-meta">Везёт: <?= View::e($d['car_name']) ?></p><?php endif; ?>
    <?php // Контакты сторон — только сторонам (заказчику и исполнителю); contact_links экранирует сам. ?>
    <?php if ($private && $isRequester && $d['carrier_id'] !== null): ?>
        <p>Контакт исполнителя: <?= contact_links(family_contact((string) $d['car_name'], $d['car_tg'] ?? null, (string) $d['car_email'])) ?></p>
    <?php elseif ($private && !$isRequester): ?>
        <p>Контакт заказчика: <?= contact_links(family_contact((string) $d['req_name'], $d['req_tg'] ?? null, (string) $d['req_email'])) ?></p>
    <?php endif; ?>
    <?php if ($private && $d['kind'] === 'pickup' && ($d['pickup_code'] ?? '') !== ''): ?>
        <p><strong>Код или номер заказа:</strong> <?= View::e($d['pickup_code']) ?></p>
    <?php endif; ?>
</div>

<?php if ($private && $d['kind'] === 'buy' && ($d['receipt_sum'] !== null || $receipts)): ?>
    <section class="res-card delivery-receipt">
        <h2>Чек</h2>
        <?php if ($d['receipt_sum'] !== null): ?><p class="buy-price"><b><?= View::e(buy_money((string) $d['receipt_sum'])) ?> ₽</b> по чеку</p><?php endif; ?>
        <?php if ($receipts): ?>
            <div class="tool-gallery">
                <?php foreach ($receipts as $img): ?>
                    <img class="photo-thumb js-photo-full" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>"
                         data-full="<?= View::e(entry_image_url($img['path'])) ?>" alt="Фото чека" loading="lazy">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($actions): ?>
    <section class="res-card">
        <?php if ($has(P::DELIVER)): ?>
            <form class="res-form" method="post" action="/poselenie/dostavka/<?= $id ?>/privez" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <?php if ($d['kind'] === 'buy'): ?>
                    <label>Сумма по чеку, ₽ (необязательно)
                        <input type="text" name="receipt_sum" inputmode="decimal">
                    </label>
                    <label class="file-btn">Приложить фото чека
                        <input type="file" name="photos[]" id="receiptPhotos" data-max="<?= $room ?>" accept="image/*" multiple hidden>
                    </label>
                    <div id="receiptPhotoPreview" class="photo-preview"></div>
                <?php endif; ?>
                <button class="res-btn" type="submit">Привёз</button>
            </form>
        <?php endif; ?>
        <?php if ($has(P::ADD_RECEIPT)): ?>
            <form class="res-form" method="post" action="/poselenie/dostavka/<?= $id ?>/chek" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <label class="file-btn">Добавить фото чека
                    <input type="file" name="photos[]" id="receiptPhotos" data-max="<?= $room ?>" accept="image/*" multiple hidden>
                </label>
                <div id="receiptPhotoPreview" class="photo-preview"></div>
                <button class="res-btn" type="submit">Загрузить</button>
            </form>
        <?php endif; ?>
        <div class="buy-stage-actions">
            <?php if ($has(P::TAKE)): ?><?= $post('vzyat', 'Возьму') ?><?php endif; ?>
            <?php if ($has(P::SETTLE)): ?><?= $post('rasschitalis', 'Получил, рассчитались') ?><?php endif; ?>
            <?php if ($has(P::TO_BOARD)): ?><?= $post('na-dosku', 'Выложить на доску') ?><?php endif; ?>
            <?php if ($has(P::DECLINE) || $has(P::DROP)): ?><?= $post('ne-smogu', 'Не смогу', 'res-btn res-btn--ghost') ?><?php endif; ?>
            <?php if ($has(P::UNASSIGN)): ?><?= $post('snyat-ispolnitelya', 'Отказаться от исполнителя', 'res-btn res-btn--ghost') ?><?php endif; ?>
            <?php if ($has(P::CANCEL)): ?><?= $post('otmenit', 'Отменить', 'res-btn res-btn--ghost') ?><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($private): ?><?php require __DIR__ . '/../partials/tg-links.php'; ?><?php endif; ?>
<div class="res-lightbox" id="buyLightbox" hidden><img class="res-lightbox-img" src="" alt=""></div>
<script>
// Превью выбранных фото и увеличение фото чека — как в закупках.
(function () {
  var lb = document.getElementById('buyLightbox'), img = lb && lb.querySelector('.res-lightbox-img');
  if (lb && img) {
    Array.prototype.forEach.call(document.querySelectorAll('.js-photo-full'), function (t) {
      t.addEventListener('click', function () { img.src = t.getAttribute('data-full') || t.src; lb.hidden = false; });
    });
    lb.addEventListener('click', function () { lb.hidden = true; img.src = ''; });
  }
  var inp = document.getElementById('receiptPhotos'), box = document.getElementById('receiptPhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  // Сколько ещё фото чека влезает в заявку (лимит минус уже загруженные) — считает сервер.
  var max = parseInt(inp.getAttribute('data-max'), 10) || 0;
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    Array.prototype.forEach.call(inp.files, function (f) { if (/^image\//.test(f.type) && dt.files.length < max) { dt.items.add(f); } });
    inp.files = dt.files; render();
  });
  function render() {
    box.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, idx) {
      var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
      var im = document.createElement('img'); im.className = 'photo-thumb'; im.src = URL.createObjectURL(f);
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
      btn.addEventListener('click', function () { dt.items.remove(idx); inp.files = dt.files; render(); });
      wrap.appendChild(im); wrap.appendChild(btn); box.appendChild(wrap);
    });
  }
})();
</script>
