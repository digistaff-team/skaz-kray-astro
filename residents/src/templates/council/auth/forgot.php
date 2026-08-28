<?php use SkazResidents\{Csrf, View}; ?>
<h1>Восстановление пароля</h1>
<?php if (!empty($sent)): ?>
    <div class="res-flash res-flash--success">
        Если такой email есть среди членов совета — на него отправлено письмо со ссылкой для смены пароля (действует час).
    </div>
    <p class="res-meta"><a href="/sovet/vhod">Вернуться ко входу</a></p>
<?php else: ?>
    <p class="res-meta">Укажите email, на который заведён ваш аккаунт в совете.</p>
    <form class="res-form" method="post" action="/sovet/vosstanovit">
        <?= Csrf::field() ?>
        <label>Email
            <input type="email" name="email" required autofocus>
        </label>
        <button class="res-btn" type="submit">Отправить ссылку</button>
    </form>
<?php endif; ?>
