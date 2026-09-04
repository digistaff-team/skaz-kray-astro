<?php use SkazResidents\{Csrf, View};
$isEdit = !empty($product['id']);
$action = $isEdit ? '/poselenie/yarmarka/' . (int) $product['id'] . '/redaktirovat' : '/poselenie/yarmarka/novyy';
?>
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
    <label>Цена (можно оставить пустым — «по договорённости»)
        <input type="text" name="price" value="<?= View::e($product['price'] ?? '') ?>">
    </label>
    <label>Как связаться (телефон, мессенджер и т.п.)
        <input type="text" name="contact" value="<?= View::e($product['contact'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['contact'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['contact']) ?></div><?php endif; ?>
    <?php $vis = $product['visibility'] ?? 'residents'; ?>
    <label>Где разместить
        <select name="visibility">
            <option value="residents"<?= $vis === 'residents' ? ' selected' : '' ?>>Только соседи (внутрипоселенческий рынок)</option>
            <option value="public"<?= $vis === 'public' ? ' selected' : '' ?>>На сайте (раздел «Ярмарка»)</option>
        </select>
    </label>
    <label class="file-btn">Добавить фото
        <input type="file" name="photos[]" id="prodPhotos" accept="image/*" multiple hidden>
    </label>
    <div id="prodPhotoPreview" class="photo-preview"></div>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Разместить' ?></button>
</form>

<?php if (!empty($images)): ?>
    <div class="res-meta" style="margin-top:1rem">Уже загружено (нажмите ×, чтобы удалить):</div>
    <div class="photo-preview">
        <?php foreach ($images as $img): ?>
            <form class="photo-uploaded" method="post" action="/poselenie/yarmarka/<?= (int) $product['id'] ?>/foto/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                <?= Csrf::field() ?>
                <img class="photo-thumb" src="<?= View::e(entry_image_url($img['path'])) ?>" alt="">
                <button type="submit" class="photo-del" title="Удалить фото">×</button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

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
