<?php use SkazResidents\View; use SkazResidents\Csrf; /** @var array $entries */ /** @var array $products */ /** @var array $things */ ?>
<h1 class="cab-h1">Мой кабинет</h1>

<section class="cab-sec">
    <h2>Дневник поместья</h2>
    <p><a class="res-btn cab-btn-primary" href="/poselenie/dnevnik/novaya">Новая запись</a></p>
    <?php if (!$entries): ?><p class="res-meta">Записей пока нет.</p><?php endif; ?>
    <?php foreach ($entries as $e): ?>
        <div class="res-card cab-item">
            <div class="cab-item-head">
                <strong class="cab-item-title"><?= View::e($e['title']) ?></strong>
                <span class="res-status res-status--<?= View::e($e['status']) ?>"><?= View::e(status_label($e['status'])) ?></span>
            </div>
            <?php if ($e['status'] === 'rejected' && $e['reject_reason']): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($e['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta"><?= View::e(ru_date($e['created_at'] ?? '')) ?></p>
            <div class="cab-item-actions">
                <a class="res-btn res-btn--ghost" href="/poselenie/dnevnik/<?= (int) $e['id'] ?>/redaktirovat">Изменить</a>
                <form method="post" action="/poselenie/dnevnik/<?= (int) $e['id'] ?>/udalit" onsubmit="return confirm('Удалить запись?')">
                    <?= Csrf::field() ?>
                    <button type="submit" class="res-btn res-btn--muted">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section class="cab-sec">
    <h2>Мои вещи в общем</h2>
    <?php foreach ($things as $t): ?>
        <a class="cab-thing" href="<?= View::e($t['href']) ?>">
            <span class="cab-thing-name"><?= View::e($t['name']) ?></span>
            <span class="cab-thing-meta"><?= View::e($t['meta']) ?></span>
        </a>
    <?php endforeach; ?>
</section>

<section class="cab-sec">
    <h2>Товары и услуги</h2>
    <p class="res-meta">Внутрипоселенческий рынок: посмотрите, что предлагают соседи, или разместите своё.</p>
    <p><a class="res-btn" href="/poselenie/yarmarka">Товары соседей</a> <a class="res-btn res-btn--ghost" href="/poselenie/yarmarka/moya">Моя витрина</a></p>
</section>
