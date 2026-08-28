<?php use SkazResidents\{Csrf, View}; ?>
<h1>Смена пароля</h1>
<?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
<form class="res-form" method="post" action="/sovet/parol">
    <?= Csrf::field() ?>
    <label>Текущий пароль
        <input type="password" name="current" required>
    </label>
    <label>Новый пароль (не короче 8 символов)
        <input type="password" name="password" required>
    </label>
    <button class="res-btn" type="submit">Изменить пароль</button>
</form>
