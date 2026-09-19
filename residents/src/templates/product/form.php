<?php use SkazResidents\{Csrf, View};
$isEdit = !empty($product['id']);
$action = $isEdit ? '/poselenie/yarmarka/' . (int) $product['id'] . '/redaktirovat' : '/poselenie/yarmarka/novyy';
$cancelUrl = $isEdit ? '/poselenie/yarmarka/moya' : '/poselenie/yarmarka';
?>
<?php $backFallback = $cancelUrl; require __DIR__ . '/../partials/back.php'; ?>
<h1><?= $isEdit ? 'Редактирование' : 'Новый товар или услуга' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Название
        <input type="text" name="title" value="<?= View::e($product['title'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Описание
        <textarea name="description" required><?= View::e($product['description'] ?? '') ?></textarea>
    </label>
    <?php if (isset($errors['description'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['description']) ?></div><?php endif; ?>
    <?php $curUnit = (string) ($product['unit'] ?? ''); $units = $units ?? []; ?>
    <div class="market-price-row<?= ($product['price'] ?? '') !== '' ? ' has-unit' : '' ?>" id="prodPriceRow">
        <label class="market-price-value">Цена, руб.
            <input type="text" name="price" id="prodPrice" inputmode="decimal" value="<?= View::e($product['price'] ?? '') ?>">
        </label>
        <label class="market-price-unit" id="prodUnitBox"<?= ($product['price'] ?? '') !== '' ? '' : ' hidden' ?>>Единица изм.
            <select name="unit">
                <option value="">— не указана —</option>
                <?php if ($curUnit !== '' && !in_array($curUnit, $units, true)): ?>
                    <option value="<?= View::e($curUnit) ?>" selected><?= View::e($curUnit) ?></option>
                <?php endif; ?>
                <?php foreach ($units as $u): ?>
                    <option value="<?= View::e($u) ?>"<?= $curUnit === $u ? ' selected' : '' ?>><?= View::e($u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <?php if (isset($errors['price'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['price']) ?></div><?php endif; ?>
    <label>Как связаться (телефон, мессенджер и т.п.)
        <input type="text" name="contact" value="<?= View::e($product['contact'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['contact'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['contact']) ?></div><?php endif; ?>
    <?php $vis = $product['visibility'] ?? 'residents'; ?>
    <label>Где разместить
        <select name="visibility">
            <option value="residents"<?= $vis === 'residents' ? ' selected' : '' ?>>Только для соседей</option>
            <option value="public"<?= $vis === 'public' ? ' selected' : '' ?>>Для всех на сайте</option>
        </select>
    </label>
    <label class="file-btn">Добавить фото
        <input type="file" name="photos[]" id="prodPhotos" accept="image/*" multiple hidden>
    </label>
    <div id="prodPhotoPreview" class="photo-preview"></div>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Разместить' ?></button>
    <a class="res-btn res-btn--ghost js-back" href="<?= View::e($cancelUrl) ?>">Отменить</a>
</form>

<?php if (!empty($images)): ?>
    <div class="res-meta" style="margin-top:1rem">Уже загружено (нажмите ×, чтобы удалить):</div>
    <div class="photo-preview">
        <?php foreach ($images as $img): ?>
            <form class="photo-uploaded" method="post" action="/poselenie/yarmarka/<?= (int) $product['id'] ?>/foto/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                <?= Csrf::field() ?>
                <img class="photo-thumb" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>" alt="">
                <button type="submit" class="photo-del" title="Удалить фото">×</button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// «Единица изм.» показывается справа от цены только когда цена заполнена:
// без цены («по договорённости») единица не имеет смысла.
(function () {
  var price = document.getElementById('prodPrice'),
      box = document.getElementById('prodUnitBox'),
      row = document.getElementById('prodPriceRow');
  if (!price || !box || !row) { return; }
  function sync() {
    var on = price.value.trim() !== '';
    box.hidden = !on;
    row.classList.toggle('has-unit', on);
  }
  // В цене только цифры и один разделитель копеек: буквы и знаки валюты выбрасываем
  // прямо при вводе (та же проверка есть и на сервере).
  function digitsOnly() {
    var v = price.value.replace(/[^\d.,]/g, '').replace(/,/g, '.');
    var i = v.indexOf('.');
    if (i !== -1) { v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, ''); }
    if (v !== price.value) {
      var pos = price.selectionStart, cut = price.value.length - v.length;
      price.value = v;
      if (pos !== null) { try { price.setSelectionRange(pos - cut, pos - cut); } catch (e) {} }
    }
  }
  price.addEventListener('input', function () { digitsOnly(); sync(); });
  sync();
})();
</script>

<script>
(function () {
  var inp = document.getElementById('prodPhotos'), box = document.getElementById('prodPhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    Array.prototype.forEach.call(inp.files, function (f) { if (/^image\//.test(f.type)) { dt.items.add(f); } });
    inp.files = dt.files; render();
  });
  function render() {
    box.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, idx) {
      var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
      var img = document.createElement('img'); img.className = 'photo-thumb'; img.src = URL.createObjectURL(f);
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
      btn.addEventListener('click', function () { dt.items.remove(idx); inp.files = dt.files; render(); });
      wrap.appendChild(img); wrap.appendChild(btn); box.appendChild(wrap);
    });
  }
})();
</script>
