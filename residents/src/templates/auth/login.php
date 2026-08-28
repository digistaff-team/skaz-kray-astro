<?php use SkazResidents\{Csrf, View}; ?>
<h1>Вход для жителей</h1>
<?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
<form class="res-form" method="post" action="/poselenie/login">
    <?= Csrf::field() ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
    </label>
    <label>Пароль
        <input type="password" name="password" required>
    </label>
    <button class="res-btn" type="submit">Войти</button>
</form>
<p class="res-meta">
    Нет аккаунта? <a href="/poselenie/register">Подать заявку</a>.<br>
    <a href="/poselenie/vosstanovit">Забыли пароль?</a>
</p>
