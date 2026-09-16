<?php
use SkazResidents\{Csrf, View, Config};
$isEdit = !empty($book['id']);
$action = $isEdit ? '/poselenie/knigi/' . (int) $book['id'] . '/redaktirovat' : '/poselenie/knigi/novaya';
$uploadsUrl = rtrim((string) Config::get('uploads_url'), '/');
?>
<h1><?= $isEdit ? 'Редактирование книги' : 'Поделиться книгой' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Название
        <input type="text" name="title" maxlength="250" value="<?= View::e($book['title'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Автор        <input type="text" name="author" maxlength="200" value="<?= View::e($book['author'] ?? '') ?>">
    </label>
    <label>Жанр
        <?php
            // Популярные жанры + уже встречающиеся в каталоге (без дублей).
            $popularGenres = ['Фантастика', 'Детектив', 'Роман', 'Психология', 'Классика', 'Детская книга'];
            $genreOptions = $popularGenres;
            foreach ($genres as $g) { if (!in_array($g, $genreOptions, true)) { $genreOptions[] = $g; } }
            $curGenre = (string) ($book['genre'] ?? '');
        ?>
        <select name="genre">
            <option value="">— выберите —</option>
            <?php if ($curGenre !== '' && !in_array($curGenre, $genreOptions, true)): ?>
                <option value="<?= View::e($curGenre) ?>" selected><?= View::e($curGenre) ?></option>
            <?php endif; ?>
            <?php foreach ($genreOptions as $g): ?>
                <option value="<?= View::e($g) ?>"<?= $curGenre === $g ? ' selected' : '' ?>><?= View::e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if (isset($errors['genre'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['genre']) ?></div><?php endif; ?>
    <label>Состояние книги
        <?php
            $condOptions = ['Новая', 'Хорошее', 'Потрёпанная'];
            $curCond = (string) ($book['condition_note'] ?? '');
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
    <label>Статус книги
        <?php $curStatus = (($book['status'] ?? 'available') === 'on_loan') ? 'on_loan' : 'available'; ?>
        <select name="status">
            <option value="available"<?= $curStatus === 'available' ? ' selected' : '' ?>>Книга свободна</option>
            <option value="on_loan"<?= $curStatus === 'on_loan' ? ' selected' : '' ?>>Книга на руках</option>
        </select>
    </label>
    <label>Аннотация / о чём книга
        <textarea name="description"><?= View::e($book['description'] ?? '') ?></textarea>
    </label>
    <label class="file-btn">Добавить фото обложки
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
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить в каталог' ?></button>
</form>

<script>
// Клиентское превью выбранных фото (как в профиле/дневнике): накопительный выбор.
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
