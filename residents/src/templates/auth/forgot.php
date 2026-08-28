<?php use SkazResidents\Csrf; ?>
<h1>Восстановление пароля</h1>
<?php if (!empty($sent)): ?>
    <div class="res-flash res-flash--success">Если такой email зарегистрирован, мы отправили на него ссылку для сброса пароля. Если письмо не пришло — обратитесь к редактору поселения, он сбросит пароль вручную.</div>
<?php else: ?>
    <form class="res-form" method="post" action="/poselenie/vosstanovit">
        <?= Csrf::field() ?>
        <label>Email
            <input type="email" name="email" required>
        </label>
        <button class="res-btn" type="submit">Прислать ссылку</button>
    </form>
<?php endif; ?>
