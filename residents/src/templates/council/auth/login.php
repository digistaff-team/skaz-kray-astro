<?php use SkazResidents\{Csrf, View}; ?>
<h1>Вход для членов совета</h1>
<p class="res-meta">Раздел Попечительского совета — только для его членов. Доступ выдаёт администратор совета.</p>
<?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
<form class="res-form" method="post" action="/sovet/login">
    <?= Csrf::field() ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required autofocus>
    </label>
    <label>Пароль
        <input type="password" name="password" required>
    </label>
    <button class="res-btn" type="submit">Войти</button>
</form>
<p class="res-meta"><a href="/sovet/vosstanovit">Забыли пароль?</a></p>
