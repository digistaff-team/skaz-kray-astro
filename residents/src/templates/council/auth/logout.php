<?php use SkazResidents\Csrf; ?>
<h1>Выйти из раздела совета?</h1>
<form class="res-form" method="post" action="/sovet/vyhod">
    <?= Csrf::field() ?>
    <button class="res-btn" type="submit">Выйти</button>
    <a class="res-btn res-btn--ghost" href="/sovet">Остаться</a>
</form>
