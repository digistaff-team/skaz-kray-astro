<?php use SkazResidents\{Csrf, View};
$isEdit = !empty($entry['id']);
$action = $isEdit ? '/poselenie/dnevnik/' . (int) $entry['id'] . '/redaktirovat' : '/poselenie/dnevnik/novaya';
?>
<h1><?= $isEdit ? 'Редактирование записи' : 'Новая запись дневника' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Заголовок
        <input type="text" name="title" value="<?= View::e($entry['title'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Текст записи
        <textarea name="body" required><?= View::e($entry['body'] ?? '') ?></textarea>
    </label>
    <?php if (isset($errors['body'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['body']) ?></div><?php endif; ?>
    <?php $vis = $entry['visibility'] ?? 'residents'; ?>
    <label>Кто видит эту запись
        <select name="visibility">
            <option value="private"<?= $vis === 'private' ? ' selected' : '' ?>>Только я</option>
            <option value="residents"<?= $vis === 'residents' ? ' selected' : '' ?>>Только соседи</option>
            <option value="public"<?= $vis === 'public' ? ' selected' : '' ?>>Все на сайте</option>
        </select>
    </label>
    <label>Добавить фото (JPEG/PNG/WebP, до 5 МБ)
        <input type="file" name="photos[]" id="diaryPhotos" accept="image/*" multiple>
    </label>
    <div id="diaryPhotoPreview" class="photo-preview"></div>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Отправить' ?></button>
</form>

<?php if (!empty($images)): ?>
    <div class="res-meta" style="margin-top:1rem">Уже загружено (нажмите ×, чтобы удалить):</div>
    <div class="photo-preview">
        <?php foreach ($images as $img): ?>
            <form class="photo-uploaded" method="post" action="/poselenie/dnevnik/<?= (int) $entry['id'] ?>/foto/<?= (int) $img['id'] ?>/udalit" onsubmit="return confirm('Удалить это фото?')">
                <?= Csrf::field() ?>
                <img class="photo-thumb" src="<?= View::e(entry_image_url($img['path'])) ?>" alt="">
                <button type="submit" class="photo-del" title="Удалить фото">×</button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
  var inp = document.getElementById('diaryPhotos'), box = document.getElementById('diaryPhotoPreview');
  if (!inp || !box || typeof DataTransfer === 'undefined') { return; }
  var dt = new DataTransfer();
  inp.addEventListener('change', function () {
    // Накопительно добавляем выбранные фото (можно выбирать несколько раз).
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
