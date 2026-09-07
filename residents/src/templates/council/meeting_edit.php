<?php use SkazResidents\{Csrf, View}; ?>
<h1>Ближайшее собрание</h1>
<p class="res-meta">Правит текущий Дежурный председатель или администратор совета. Повестка — по одному пункту на строку.</p>

<div class="res-card">
    <form class="res-form" method="post" action="/sovet/vstrecha">
        <?= Csrf::field() ?>
        <label>Дата и время
            <input type="text" name="date" maxlength="160" placeholder="7 сентября 2026, 18:00–20:00"
                   value="<?= View::e($meeting['date']) ?>" required>
            <?php if (!empty($errors['date'])): ?><span class="sovet-err"><?= View::e($errors['date']) ?></span><?php endif; ?>
        </label>
        <label>Место
            <input type="text" name="place" maxlength="200"
                   value="<?= View::e($meeting['place']) ?>" required>
            <?php if (!empty($errors['place'])): ?><span class="sovet-err"><?= View::e($errors['place']) ?></span><?php endif; ?>
        </label>
        <label>Дежурный председатель
            <input type="text" name="duty_chair" maxlength="160"
                   value="<?= View::e($meeting['dutyChair']) ?>">
            <?php if (!empty($errors['duty_chair'])): ?><span class="sovet-err"><?= View::e($errors['duty_chair']) ?></span><?php endif; ?>
        </label>
        <label>Дежурный секретарь
            <input type="text" name="duty_secretary" maxlength="160"
                   value="<?= View::e($meeting['dutySecretary']) ?>">
            <?php if (!empty($errors['duty_secretary'])): ?><span class="sovet-err"><?= View::e($errors['duty_secretary']) ?></span><?php endif; ?>
        </label>
        <label>Повестка (по пункту на строку)
            <textarea name="agenda" rows="6"><?= View::e($agendaText) ?></textarea>
        </label>
        <div class="sovet-form-actions">
            <button class="res-btn" type="submit">Сохранить</button>
            <a class="res-link-btn" href="/sovet">Отмена</a>
        </div>
    </form>
</div>
