<?php
use SkazResidents\{Csrf, View};
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="res-flash res-flash--error">' . View::e($errors[$k]) . '</div>' : '';
$e   = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$path = '/poselenie/moderation/rassylka/spiski/' . (int) $list['id'];
?>
<?php $backFallback = '/poselenie/moderation/rassylka'; require __DIR__ . '/../partials/back.php'; ?>
<h1>Список: <?= $e($list['name']) ?></h1>

<p class="res-meta">Список — сохранённый набор адресатов для рассылки. При отправке сообщение получит только то поместье, у которого на тот момент привязан Telegram и статус «активно».</p>

<section>
    <h2>Название</h2>
    <form class="res-form" method="post" action="<?= $path ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="rename">
        <label>Название списка
            <input type="text" name="name" maxlength="120" required value="<?= $e($old['name'] ?? $list['name']) ?>">
        </label>
        <?= $err('name') ?>
        <button class="res-btn" type="submit">Сохранить название</button>
    </form>
</section>

<section>
    <h2>Адресаты (<?= count($members) ?>)</h2>

    <?php if (!$members): ?>
        <p class="res-meta">В списке пока никого нет — добавьте первого адресата ниже.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($members as $m): ?>
                <li>
                    <?= $e($m['name']) ?>
                    <?php if ((string) ($m['telegram_id'] ?? '') !== '' && (string) ($m['status'] ?? '') === 'active'): ?>
                        <span class="res-meta">— получит рассылку</span>
                    <?php else: ?>
                        <span class="res-meta">— не получит (нет Telegram или не активно)</span>
                    <?php endif; ?>
                    <form method="post" action="<?= $path ?>" style="display:inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="family_id" value="<?= (int) $m['family_id'] ?>">
                        <button class="res-btn res-btn--ghost" type="submit">Убрать</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3>Добавить адресата</h3>
    <form class="res-form" method="post" action="<?= $path ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">
        <label>Поместье (только те, у кого привязан Telegram)
            <select name="family_id">
                <option value="0">— выберите поместье —</option>
                <?php foreach ($addable as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"><?= $e($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>…или введите email либо Telegram ID поместья
            <input type="text" name="who" placeholder="semya@skaz-kray.ru или 123456">
        </label>
        <button class="res-btn" type="submit">Добавить в список</button>
    </form>
</section>

<section>
    <h2>Опасная зона</h2>
    <form class="res-form" method="post" action="<?= $path ?>" data-confirm="Удалить список «<?= $e($list['name']) ?>»?">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">
        <p class="res-meta">Удаление списка не трогает сами поместья — просто список перестаёт существовать.</p>
        <button class="res-btn" type="submit">Удалить список</button>
    </form>
</section>
