<?php use SkazResidents\{Csrf, View}; ?>
<h1>Разделы приложения</h1>
<p class="res-meta">Управление сайтом — только для администратора. Выключенный раздел исчезает из плиток на главной и из меню. «Наше поместье» — базовый раздел, его выключить нельзя.</p>

<p><a href="/poselenie/moderation">← К модерации</a></p>

<div class="res-card">
    <?php foreach ($sections as $key => $label): $on = !in_array($key, $disabled, true); ?>
        <div class="res-section-row">
            <span><strong><?= View::e($label) ?></strong> — <?= $on ? 'показывается' : 'скрыт' ?></span>
            <form method="post" action="/poselenie/moderation/razdely/pereklyuchit" style="display:inline;margin:0">
                <?= Csrf::field() ?><input type="hidden" name="key" value="<?= View::e($key) ?>">
                <input type="hidden" name="enable" value="<?= $on ? '0' : '1' ?>">
                <button class="res-btn<?= $on ? ' res-btn--ghost' : '' ?>" type="submit"><?= $on ? 'Выключить' : 'Включить' ?></button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<style>
.res-section-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid rgba(0,0,0,.08); }
.res-section-row:last-child { border-bottom:0; }
</style>
