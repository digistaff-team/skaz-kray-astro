<?php use SkazResidents\View; use SkazResidents\Csrf; ?>
<h1>Личный кабинет</h1>

<section>
    <h2>Дневник поместья</h2>
    <p>
        <a class="res-btn" href="/poselenie/dnevnik/novaya">Новая запись</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/dnevniki">Дневники всех жителей</a>
    </p>
    <?php if (!$entries): ?><p class="res-meta">Записей пока нет.</p><?php endif; ?>
    <?php foreach ($entries as $e): ?>
        <div class="res-card">
            <strong><?= View::e($e['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($e['status']) ?>"><?= View::e(status_label($e['status'])) ?></span>
            <?php if ($e['status'] === 'rejected' && $e['reject_reason']): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($e['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta">
                <a href="/poselenie/dnevnik/<?= (int) $e['id'] ?>/redaktirovat">Редактировать</a> ·
                <form method="post" action="/poselenie/dnevnik/<?= (int) $e['id'] ?>/udalit" style="display:inline" onsubmit="return confirm('Удалить запись?')">
                    <?= Csrf::field() ?>
                    <button type="submit" class="res-link-btn">Удалить</button>
                </form>
            </p>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Шеринг инструментов</h2>
    <p class="res-meta">Общая копилка инструментов жителей — возьмите нужное у соседа или поделитесь своим.</p>
    <p>
        <a class="res-btn" href="/poselenie/instrumenty">Каталог инструментов</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/instrumenty/moi">Мои инструменты и заявки</a>
    </p>
</section>

<section>
    <h2>Обмен книгами</h2>
    <p class="res-meta">Общая книжная полка жителей — возьмите книгу почитать или поделитесь своей.</p>
    <p>
        <a class="res-btn" href="/poselenie/knigi">Каталог книг</a>
        <a class="res-btn res-btn--ghost" href="/poselenie/knigi/moi">Мои книги и брони</a>
    </p>
</section>

<section>
    <h2>Мои товары и услуги</h2>
    <p><a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить товар/услугу</a></p>
    <?php if (!$products): ?><p class="res-meta">Пока ничего не добавлено.</p><?php endif; ?>
    <?php foreach ($products as $p): ?>
        <div class="res-card">
            <strong><?= View::e($p['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($p['status']) ?>"><?= View::e(status_label($p['status'])) ?></span>
            <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($p['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta">
                <a href="/poselenie/yarmarka/<?= (int) $p['id'] ?>/redaktirovat">Редактировать</a> ·
                <form method="post" action="/poselenie/yarmarka/<?= (int) $p['id'] ?>/udalit" style="display:inline" onsubmit="return confirm('Удалить?')">
                    <?= Csrf::field() ?>
                    <button type="submit" class="res-link-btn">Удалить</button>
                </form>
            </p>
        </div>
    <?php endforeach; ?>
</section>
