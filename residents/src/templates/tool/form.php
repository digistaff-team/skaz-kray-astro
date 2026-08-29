<?php
use SkazResidents\{Csrf, View, Config};
$isEdit = !empty($tool['id']);
$action = $isEdit ? '/poselenie/instrumenty/' . (int) $tool['id'] . '/redaktirovat' : '/poselenie/instrumenty/novyy';
$uploadsUrl = rtrim((string) Config::get('uploads_url'), '/');
?>
<h1><?= $isEdit ? 'Редактирование инструмента' : 'Поделиться инструментом' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Название
        <input type="text" name="name" maxlength="200" value="<?= View::e($tool['name'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['name']) ?></div><?php endif; ?>
    <label>Категория (необязательно)
        <input type="text" name="category" maxlength="80" list="tool-cats" value="<?= View::e($tool['category'] ?? '') ?>" placeholder="напр. Электроинструмент, Садовый, Измерительный">
        <datalist id="tool-cats">
            <?php foreach ($categories as $c): ?><option value="<?= View::e($c) ?>"><?php endforeach; ?>
        </datalist>
    </label>
    <?php if (isset($errors['category'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['category']) ?></div><?php endif; ?>
    <label>Состояние (необязательно)
        <input type="text" name="condition_note" maxlength="200" value="<?= View::e($tool['condition_note'] ?? '') ?>" placeholder="напр. рабочее, есть небольшой люфт">
    </label>
    <label>Условия/залог (необязательно)
        <input type="text" name="terms" maxlength="200" value="<?= View::e($tool['terms'] ?? '') ?>" placeholder="напр. без залога; вернуть заряженным">
    </label>
    <label>Описание (необязательно)
        <textarea name="description"><?= View::e($tool['description'] ?? '') ?></textarea>
    </label>
    <label>Фотографии (JPEG/PNG/WebP, до 5 МБ)
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
