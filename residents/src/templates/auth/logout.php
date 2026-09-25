<?php use SkazResidents\Csrf; ?>
<h1>Выйти из кабинета?</h1>
<form class="res-form" method="post" action="/poselenie/vyhod">
    <?= Csrf::field() ?>
    <button class="res-btn" type="submit">Выйти</button>
    <a class="res-btn res-btn--ghost" href="/poselenie/app">Остаться</a>
</form>
