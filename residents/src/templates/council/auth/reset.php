<?php use SkazResidents\{Csrf, View}; ?>
<h1>Новый пароль</h1>
<?php if (empty($valid)): ?>
    <div class="res-flash res-flash--error">Ссылка недействительна или устарела. Запросите новую на странице <a href="/sovet/vosstanovit">восстановления пароля</a>.</div>
<?php else: ?>
    <?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
    <form class="res-form" method="post" action="/sovet/sbros">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= View::e($token) ?>">
        <label>Новый пароль (не короче 8 символов)
            <input type="password" name="password" required autofocus>
        </label>
        <button class="res-btn" type="submit">Сохранить пароль</button>
    </form>
<?php endif; ?>
