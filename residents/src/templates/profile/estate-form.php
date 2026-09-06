<?php
use SkazResidents\{Csrf, View};
/** @var array $household @var array $errors */
?>
<h1>Название поместья</h1>
<form class="res-form" method="post" action="/poselenie/moye-pomestie/nazvanie">
    <?= Csrf::field() ?>
    <label>Название поместья (можно оставить пустым)
        <input type="text" name="estate_name" value="<?= View::e((string) $household['estate_name']) ?>" placeholder="напр. АгудариЯ">
    </label>
    <?php if (isset($errors['estate_name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['estate_name']) ?></div><?php endif; ?>
    <button class="res-btn" type="submit">Сохранить</button>
    <a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie">Отмена</a>
</form>
