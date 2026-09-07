<?php use SkazResidents\{Csrf, View}; ?>
<h1>Повестка встречи</h1>
<p class="res-meta">Встреча: <strong><?= View::e($meeting['date']) ?></strong>. Любой член совета может добавить пункт. Необсуждённые пункты переходят на следующую встречу.</p>

<details class="sovet-newtask">
    <summary class="res-btn">+ Добавить пункт</summary>
    <form class="res-form" method="post" action="/sovet/povestka/dobavit">
        <?= Csrf::field() ?>
        <label>Пункт повестки
            <input type="text" name="title" maxlength="500" placeholder="Что вынести на обсуждение" required>
        </label>
        <div class="sovet-form-actions">
            <button class="res-btn" type="submit">Добавить</button>
            <button class="res-btn res-btn--ghost" type="button" onclick="this.closest('details').removeAttribute('open')">Отменить</button>
        </div>
    </form>
</details>

<?php if (!$items): ?>
    <p class="res-meta sovet-empty">Повестка пока пуста — добавьте первый пункт 👆</p>
<?php endif; ?>

<ul class="sovet-agenda-list">
    <?php foreach ($items as $it): $iid = (int) $it['id']; $disc = !empty($it['discussed']); ?>
        <li class="sovet-agenda-item<?= $disc ? ' is-discussed' : '' ?>">
            <?php if ($isEditor): ?>
                <form method="post" action="/sovet/povestka/obsuzhdeno" class="sovet-agenda-check">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $iid ?>">
                    <input type="checkbox" onchange="this.form.submit()"<?= $disc ? ' checked' : '' ?> title="Отметить обсуждённым">
                </form>
            <?php else: ?>
                <span class="sovet-agenda-check"><input type="checkbox" disabled<?= $disc ? ' checked' : '' ?> title="Обсуждено"></span>
            <?php endif; ?>
            <div class="sovet-agenda-body">
                <span class="sovet-agenda-text"><?= View::e($it['title']) ?></span>
                <?php if ($it['author'] !== ''): ?><span class="sovet-agenda-author">— <?= View::e($it['author']) ?></span><?php endif; ?>
                <?php if (!empty($it['carried_over'])): ?><span class="sovet-agenda-carried">с прошлой встречи</span><?php endif; ?>
            </div>
            <div class="sovet-agenda-actions">
                <?php if ($isEditor): ?>
                    <form method="post" action="/sovet/povestka/peremestit"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $iid ?>"><input type="hidden" name="dir" value="up"><button class="res-link-btn" type="submit" title="Выше">↑</button></form>
                    <form method="post" action="/sovet/povestka/peremestit"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $iid ?>"><input type="hidden" name="dir" value="down"><button class="res-link-btn" type="submit" title="Ниже">↓</button></form>
                <?php endif; ?>
                <?php if ($isEditor || (string) $it['author'] === $me): ?>
                    <form method="post" action="/sovet/povestka/udalit"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $iid ?>"><button class="res-link-btn sovet-danger" type="submit">удалить</button></form>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
