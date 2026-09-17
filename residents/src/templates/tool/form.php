<?php
use SkazResidents\{Csrf, View, Config};
$isEdit = !empty($tool['id']);
$action = $isEdit ? '/poselenie/instrumenty/' . (int) $tool['id'] . '/redaktirovat' : '/poselenie/instrumenty/novyy';
$uploadsUrl = rtrim((string) Config::get('uploads_url'), '/');
$cancelUrl = $isEdit ? '/poselenie/instrumenty/' . (int) $tool['id'] : '/poselenie/instrumenty';
?>
<a class="res-back" href="/poselenie/app">← На главную</a>
<h1><?= $isEdit ? 'Редактирование инструмента' : 'Поделиться инструментом' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Название
        <input type="text" name="name" maxlength="200" value="<?= View::e($tool['name'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['name']) ?></div><?php endif; ?>
    <label>Категория
        <?php $curCat = (string) ($tool['category'] ?? ''); ?>
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
    <?php if (isset($errors['category'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['category']) ?></div><?php endif; ?>
    <label>Состояние инструмента
        <?php
            $condOptions = ['Новый', 'Хорошее', 'Рабочее с нюансами'];
            $curCond = (string) ($tool['condition_note'] ?? '');
        ?>
        <select name="condition_note">
            <option value="">— выберите —</option>
            <?php if ($curCond !== '' && !in_array($curCond, $condOptions, true)): ?>
                <option value="<?= View::e($curCond) ?>" selected><?= View::e($curCond) ?></option>
            <?php endif; ?>
            <?php foreach ($condOptions as $c): ?>
                <option value="<?= View::e($c) ?>"<?= $curCond === $c ? ' selected' : '' ?>><?= View::e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Статус инструмента
        <?php $curStatus = (($tool['status'] ?? 'available') === 'on_loan') ? 'on_loan' : 'available'; ?>
        <select name="status">
            <option value="available"<?= $curStatus === 'available' ? ' selected' : '' ?>>Инструмент свободен</option>
            <option value="on_loan"<?= $curStatus === 'on_loan' ? ' selected' : '' ?>>Инструмент на руках</option>
        </select>
    </label>
    <label>Условия и залог
        <input type="text" name="terms" maxlength="200" value="<?= View::e($tool['terms'] ?? '') ?>">
    </label>
    <label>Описание / для чего инструмент
        <textarea name="description"><?= View::e($tool['description'] ?? '') ?></textarea>
    </label>
    <label class="file-btn">Добавить фото инструмента
        <input type="file" name="photos[]" id="profilePhotos" accept="image/*" multiple hidden>
    </label>
    <div id="profilePhotoPreview" class="photo-preview"></div>
    <?php if (!empty($images)): ?>
        <div class="res-meta" style="margin-top:1rem">Уже загружено:</div>
        <div class="tool-gallery">
            <?php foreach ($images as $img): ?>
                <img src="<?= View::e($uploadsUrl) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить инструмент в каталог' ?></button>
    <a class="res-btn res-btn--ghost" href="<?= View::e($cancelUrl) ?>">Отмена</a>
</form>

<script>
// Клиентское превью выбранных фото (как в профиле/дневнике/книгах): накопительный выбор.
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
