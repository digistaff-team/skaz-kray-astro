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
    <label>Жанр        <input type="text" name="genre" maxlength="80" list="book-genres" value="<?= View::e($book['genre'] ?? '') ?>" placeholder="напр. Фантастика, Детская книга, Психология">
        <?php
            // Популярные жанры-подсказки + уже встречающиеся в каталоге (без дублей).
            $popularGenres = ['Фантастика', 'Детектив', 'Роман', 'Психология', 'Классика', 'Детская книга'];
            $genreOptions = $popularGenres;
            foreach ($genres as $g) { if (!in_array($g, $genreOptions, true)) { $genreOptions[] = $g; } }
        ?>
        <datalist id="book-genres">
            <?php foreach ($genreOptions as $g): ?><option value="<?= View::e($g) ?>"><?php endforeach; ?>
        </datalist>
    </label>
    <?php if (isset($errors['genre'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['genre']) ?></div><?php endif; ?>
    <label>Состояние        <input type="text" name="condition_note" maxlength="200" value="<?= View::e($book['condition_note'] ?? '') ?>" placeholder="напр. хорошее; потрёпанная обложка">
    </label>
    <label>Аннотация / о чём книга        <textarea name="description"><?= View::e($book['description'] ?? '') ?></textarea>
    </label>
    <label>Фото обложки (JPEG/PNG/WebP, до 5 МБ)
        <input type="file" name="photos[]" accept="image/*" multiple>
    </label>
    <?php if (!empty($images)): ?>
        <div class="res-meta">Уже загружено:</div>
        <div class="tool-gallery">
            <?php foreach ($images as $img): ?>
                <img src="<?= View::e($uploadsUrl) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить в каталог' ?></button>
</form>
