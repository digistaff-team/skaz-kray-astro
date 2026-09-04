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
    <?php if (!empty($images)): ?>
        <div class="res-meta">Уже загружено:</div>
        <?php foreach ($images as $img): ?>
            <img src="<?= View::e(rtrim((string) \SkazResidents\Config::get('uploads_url'), '/')) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
        <?php endforeach; ?>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить и отправить на проверку' : 'Отправить на проверку' ?></button>
</form>
<script>
(function () {
  var inp = document.getElementById('diaryPhotos'), box = document.getElementById('diaryPhotoPreview');
  if (!inp || !box) { return; }
  inp.addEventListener('change', function () {
    box.innerHTML = '';
    Array.prototype.forEach.call(inp.files, function (f) {
      if (!/^image\//.test(f.type)) { return; }
      var img = document.createElement('img');
      img.className = 'photo-thumb';
      img.src = URL.createObjectURL(f);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      box.appendChild(img);
    });
  });
})();
</script>
