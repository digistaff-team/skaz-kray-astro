<?php use SkazResidents\{Csrf, View}; ?>
<h1>Предложить поездку</h1>
<form class="res-form" method="post" action="/poselenie/poezdki/novaya">
    <?= Csrf::field() ?>
    <div class="sovet-edit-row">
        <label>Откуда
            <input type="text" name="origin" maxlength="160" value="<?= View::e($trip['origin'] ?? '') ?>" required>
        </label>
        <label>Куда
            <input type="text" name="destination" maxlength="160" value="<?= View::e($trip['destination'] ?? '') ?>" required>
        </label>
    </div>
    <?php if (isset($errors['origin'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['origin']) ?></div><?php endif; ?>
    <?php if (isset($errors['destination'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['destination']) ?></div><?php endif; ?>
    <div class="sovet-edit-row">
        <label>Дата
            <input type="date" name="trip_date" value="<?= View::e($trip['trip_date'] ?? '') ?>" required>
        </label>
        <label>Время (необязательно)
            <input type="text" name="trip_time" maxlength="40" value="<?= View::e($trip['trip_time'] ?? '') ?>" placeholder="напр. 09:00 или по договорённости">
        </label>
        <label>Свободных мест
            <input type="number" name="seats_total" min="1" max="8" value="<?= View::e((string) ($trip['seats_total'] ?? 3)) ?>" required>
        </label>
    </div>
    <?php if (isset($errors['trip_date'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['trip_date']) ?></div><?php endif; ?>
    <?php if (isset($errors['seats_total'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['seats_total']) ?></div><?php endif; ?>
    <label>Комментарий (необязательно)
        <textarea name="note" placeholder="Условия, «за бензин», где удобно встретить…"><?= View::e($trip['note'] ?? '') ?></textarea>
    </label>
    <button class="res-btn" type="submit">Опубликовать поездку</button>
</form>
