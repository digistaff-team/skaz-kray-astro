<?php
use SkazResidents\{Csrf, View};
/** @var array|null $car @var array $errors */
$c = $car ?? [];
$isEdit = !empty($c['id']);
$action = $isEdit
    ? '/poselenie/moye-pomestie/avto/' . (int) $c['id'] . '/redaktirovat'
    : '/poselenie/moye-pomestie/avto/novyy';
$val = static fn(string $k): string => View::e((string) ($c[$k] ?? ''));
?>
<h1><?= $isEdit ? 'Изменить автомобиль' : 'Новый автомобиль' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Автомобиль (марка, модель, цвет)
        <input type="text" name="title" value="<?= $val('title') ?>" placeholder="Рено Логан, белый" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Госномер <input type="text" name="plate" value="<?= $val('plate') ?>" placeholder="А123ВС 123"></label>
    <label>Примечание <input type="text" name="note" value="<?= $val('note') ?>"></label>
    <?php require __DIR__ . '/_photo_input.php'; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить' ?></button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie">Отмена</a>
</form>
<?php $photoDeleteBase = '/poselenie/moye-pomestie/avto/' . (int) ($c['id'] ?? 0) . '/foto'; require __DIR__ . '/_photo_list.php'; ?>
