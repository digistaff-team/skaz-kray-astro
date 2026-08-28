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
