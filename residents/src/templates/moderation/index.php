<?php use SkazResidents\{Csrf, View}; ?>
<h1>Модерация</h1>

<section>
    <h2>Заявки на регистрацию (<?= count($pendingFamilies) ?>)</h2>
    <?php foreach ($pendingFamilies as $f): ?>
        <div class="res-card">
            <strong><?= View::e($f['name']) ?></strong> — <?= View::e($f['email']) ?>
            <div>
                <form method="post" action="/poselenie/moderation/family/approve" style="display:inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button class="res-btn" type="submit">Одобрить</button>
                </form>
                <form method="post" action="/poselenie/moderation/family/reject" style="display:inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingFamilies): ?><p class="res-meta">Новых заявок нет.</p><?php endif; ?>
</section>

<section>
    <h2>Записи дневника на проверке (<?= count($pendingEntries) ?>)</h2>
    <?php foreach ($pendingEntries as $e): ?>
        <div class="res-card">
            <strong><?= View::e($e['title']) ?></strong>
            <span class="res-meta">— <?= View::e($e['family_name']) ?></span>
            <p><?= nl2br(View::e($e['body'])) ?></p>
            <form method="post" action="/poselenie/moderation/entry/approve" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <button class="res-btn" type="submit">Опубликовать</button>
            </form>
            <form method="post" action="/poselenie/moderation/entry/reject" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <input type="text" name="reason" placeholder="причина отклонения">
                <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingEntries): ?><p class="res-meta">Нет записей на проверке.</p><?php endif; ?>
</section>

<section>
    <h2>Товары/услуги на проверке (<?= count($pendingProducts) ?>)</h2>
    <?php foreach ($pendingProducts as $p): ?>
        <div class="res-card">
            <strong><?= View::e($p['title']) ?></strong>
            <span class="res-meta">— <?= View::e($p['family_name']) ?></span>
            <p><?= nl2br(View::e($p['description'])) ?></p>
            <p class="res-meta">Цена: <?= $p['price'] !== null ? View::e($p['price']) : 'по договорённости' ?> · Контакт: <?= View::e($p['contact']) ?></p>
            <form method="post" action="/poselenie/moderation/product/approve" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="res-btn" type="submit">Опубликовать</button>
            </form>
            <form method="post" action="/poselenie/moderation/product/reject" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="text" name="reason" placeholder="причина отклонения">
                <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingProducts): ?><p class="res-meta">Нет товаров на проверке.</p><?php endif; ?>
</section>

<section>
    <h2>Семьи (сброс пароля)</h2>
    <?php foreach ($activeFamilies as $f): ?>
        <div class="res-card">
            <?= View::e($f['name']) ?> — <?= View::e($f['email']) ?>
            <form method="post" action="/poselenie/moderation/family/reset-password" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button class="res-btn res-btn--ghost" type="submit" onclick="return confirm('Сбросить пароль этой семье?')">Сбросить пароль</button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
