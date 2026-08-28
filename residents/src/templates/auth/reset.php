<?php use SkazResidents\{Csrf, View}; ?>
<h1>Новый пароль</h1>
<?php if (empty($valid)): ?>
    <div class="res-flash res-flash--error">Ссылка недействительна или устарела. <a href="/poselenie/vosstanovit">Запросить заново</a>.</div>
<?php else: ?>
    <?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
    <form class="res-form" method="post" action="/poselenie/sbros">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= View::e($token) ?>">
        <label>Новый пароль (не короче 8 символов)
            <input type="password" name="password" required>
        </label>
        <button class="res-btn" type="submit">Сохранить</button>
    </form>
<?php endif; ?>
