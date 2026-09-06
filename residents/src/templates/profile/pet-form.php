<?php
use SkazResidents\{Csrf, View};
/** @var array|null $pet @var array $errors */
$p = $pet ?? [];
$isEdit = !empty($p['id']);
$action = $isEdit
    ? '/poselenie/moye-pomestie/pitomec/' . (int) $p['id'] . '/redaktirovat'
    : '/poselenie/moye-pomestie/pitomec/novyy';
$val = static fn(string $k): string => View::e((string) ($p[$k] ?? ''));
?>
<h1><?= $isEdit ? 'Изменить питомца' : 'Новый питомец' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Кличка <input type="text" name="name" value="<?= $val('name') ?>" required></label>
    <?php if (isset($errors['name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['name']) ?></div><?php endif; ?>
    <label>Вид <input type="text" name="kind" value="<?= $val('kind') ?>" placeholder="кошка, собака, …"></label>
    <label>Примечание (порода, окрас и т.п.) <input type="text" name="note" value="<?= $val('note') ?>"></label>
    <?php require __DIR__ . '/_photo_input.php'; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить' ?></button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie">Отмена</a>
</form>
<?php $photoDeleteBase = '/poselenie/moye-pomestie/pitomec/' . (int) ($p['id'] ?? 0) . '/foto'; require __DIR__ . '/_photo_list.php'; ?>
