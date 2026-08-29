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
    <label class="res-checkbox">
        <input type="checkbox" name="is_public" value="1"<?= !empty($entry['is_public']) ? ' checked' : '' ?>>
        Опубликовать на внешнем сайте (в разделе «Дневники поместий» для всех посетителей)
    </label>
    <p class="res-meta">Без галочки запись видна только жителям поселения на внутреннем портале.</p>
    <label>Фотографии (JPEG/PNG/WebP, до 5 МБ)
        <input type="file" name="photos[]" accept="image/*" multiple>
    </label>
    <?php if (!empty($images)): ?>
        <div class="res-meta">Уже загружено:</div>
        <?php foreach ($images as $img): ?>
            <img src="<?= View::e(rtrim((string) \SkazResidents\Config::get('uploads_url'), '/')) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
        <?php endforeach; ?>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить и отправить на проверку' : 'Отправить на проверку' ?></button>
</form>
