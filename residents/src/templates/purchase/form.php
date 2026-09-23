<?php
use SkazResidents\{Csrf, View};
/** @var ?array $purchase @var array $images @var array $categories @var array $units @var array $errors */
$isEdit = !empty($purchase['id']);
$action = $isEdit ? '/poselenie/zakupki/' . (int) $purchase['id'] . '/redaktirovat' : '/poselenie/zakupki/novaya';
$cancelUrl = $isEdit ? '/poselenie/zakupki/' . (int) $purchase['id'] : '/poselenie/zakupki';
$val = static fn(string $k): string => View::e((string) ($purchase[$k] ?? ''));
// Числа показываем без хвоста нулей («450.00» → «450»), пустое поле — пустым.
$num = static fn(string $k): string => ($purchase[$k] ?? null) !== null ? View::e(buy_qty((string) $purchase[$k])) : '';
$curCat  = (string) ($purchase['category'] ?? '');
$curUnit = (string) ($purchase['unit'] ?? 'кг');
?>
<?php $backFallback = $cancelUrl; require __DIR__ . '/../partials/back.php'; ?>
<h1><?= $isEdit ? 'Редактирование закупки' : 'Новая закупка' ?></h1>
<p class="res-meta">Одна закупка — один товар. Если берёте цемент и доски, откройте две: так соседям понятно, к чему они присоединяются.</p>

<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Что закупаем
        <input type="text" name="title" maxlength="200" value="<?= $val('title') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>

    <label>Категория
        <select name="category">
            <option value="">— выберите —</option>
            <?php if ($curCat !== '' && !in_array($curCat, $categories, true)): ?>
                <option value="<?= View::e($curCat) ?>" selected><?= View::e($curCat) ?></option>
            <?php endif; ?>
            <?php foreach ($categories as $c): ?>
                <option value="<?= View::e($c) ?>"<?= $curCat === $c ? ' selected' : '' ?>><?= View::e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="tool-terms-row has-deposit">
        <label class="tool-terms-kind">Единица
            <select name="unit">
                <?php if ($curUnit !== '' && !in_array($curUnit, $units, true)): ?>
                    <option value="<?= View::e($curUnit) ?>" selected><?= View::e($curUnit) ?></option>
                <?php endif; ?>
                <?php foreach ($units as $u): ?>
                    <option value="<?= View::e($u) ?>"<?= $curUnit === $u ? ' selected' : '' ?>><?= View::e($u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="tool-terms-amount">Цена за единицу, ₽
            <input type="text" name="price_per_unit" inputmode="decimal" value="<?= $num('price_per_unit') ?>">
        </label>
    </div>
    <?php if (isset($errors['unit'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['unit']) ?></div><?php endif; ?>
    <?php if (isset($errors['price_per_unit'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['price_per_unit']) ?></div><?php endif; ?>

    <label>Сколько нужно набрать (цель)
        <input type="text" name="target_qty" inputmode="decimal" value="<?= $num('target_qty') ?>" placeholder="например 100">
    </label>
    <?php if (isset($errors['target_qty'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['target_qty']) ?></div><?php endif; ?>

    <label>Собираем до
        <input type="date" name="deadline" value="<?= $val('deadline') ?>">
    </label>
    <?php if (isset($errors['deadline'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['deadline']) ?></div><?php endif; ?>

    <label>Поставщик или ссылка на прайс
        <input type="text" name="supplier" maxlength="200" value="<?= $val('supplier') ?>">
    </label>

    <label>Где забирать
        <input type="text" name="pickup" maxlength="200" value="<?= $val('pickup') ?>">
    </label>

    <label>Описание и условия
        <textarea name="note"><?= $val('note') ?></textarea>
    </label>

    <label class="file-btn">Добавить фото товара
        <input type="file" name="photos[]" id="profilePhotos" accept="image/*" multiple hidden>
    </label>
    <div id="profilePhotoPreview" class="photo-preview"></div>
    <?php if (!empty($images)): ?>
        <div class="res-meta" style="margin-top:1rem">Уже загружено:</div>
        <div class="tool-gallery">
            <?php foreach ($images as $img): ?>
                <img class="photo-thumb" src="<?= View::e(entry_image_thumb($img['path'], 240)) ?>" alt="" loading="lazy">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Открыть закупку' ?></button>
    <a class="res-btn res-btn--ghost" href="<?= View::e($cancelUrl) ?>">Отмена</a>
</form>

<script>
// Клиентское превью выбранных фото (как в книгах, инструментах и дневнике).
(function () {
  var inp = document.getElementById('profilePhotos'), box = document.getElementById('profilePhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    Array.prototype.forEach.call(inp.files, function (f) { if (/^image\//.test(f.type)) { dt.items.add(f); } });
    inp.files = dt.files;
    render();
  });
  function render() {
    box.innerHTML = '';
    Array.prototype.forEach.call(dt.files, function (f, idx) {
      var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
      var img = document.createElement('img'); img.className = 'photo-thumb'; img.src = URL.createObjectURL(f);
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
      btn.addEventListener('click', function () { dt.items.remove(idx); inp.files = dt.files; render(); });
      wrap.appendChild(img); wrap.appendChild(btn); box.appendChild(wrap);
    });
  }
})();
</script>
