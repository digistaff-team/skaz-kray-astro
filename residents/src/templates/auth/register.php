<?php use SkazResidents\{Csrf, View}; ?>
<h1>Регистрация семьи</h1>
<p>Заполните заявку. После одобрения редактором вы сможете войти в кабинет.</p>
<form class="res-form" method="post" action="/poselenie/register">
    <?= Csrf::field() ?>
    <label>Название семьи / поместья
        <input type="text" name="name" value="<?= View::e($old['name'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['name']) ?></div><?php endif; ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['email'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['email']) ?></div><?php endif; ?>
    <label>Пароль (не короче 8 символов)
        <input type="password" name="password" required>
    </label>
    <?php if (isset($errors['password'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['password']) ?></div><?php endif; ?>
    <button class="res-btn" type="submit">Отправить заявку</button>
</form>
<p class="res-meta">Уже есть аккаунт? <a href="/poselenie/vhod">Войти</a>.</p>
