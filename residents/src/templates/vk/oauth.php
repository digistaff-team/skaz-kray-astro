<?php
use SkazResidents\{Csrf, View};
$e = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<?php require __DIR__ . '/../partials/back.php'; ?>
<h1>Доступ к стене ВК «Вече»</h1>

<p class="res-meta">Посты ВК-группы «Вече» пересылаются в Telegram-рассылку.
Стену ВК можно читать только от имени пользователя: ключ сообщества к методам
стены ВК не допускает. Доступ запрашивается один раз и только на чтение стены
(право <code>wall</code>); дальше токен обновляется автоматически.</p>

<?php if (!$configured): ?>
    <div class="res-flash res-flash--error">
        Приложение ВК не настроено: в <code>config/config.php</code> нужен
        <code>vk_vechesk.client_id</code>, в <code>config/.env</code> —
        <code>VK_VECHESK_CLIENT_SECRET</code>.
    </div>
<?php endif; ?>

<section>
    <h2>Состояние</h2>
    <ul>
        <li>Приложение (client_id):
            <?= $clientId !== '' ? '<code>' . $e($clientId) . '</code>' : 'не задано' ?></li>
        <li>Адрес возврата: <code><?= $e($redirectUri) ?></code></li>
        <li>Токен доступа:
            <?php if ($expiresAt === ''): ?>
                <span class="res-meta">не подключён</span>
            <?php else: ?>
                действует до <?= $e($expiresAt) ?>
                <span class="res-meta">(обновляется автоматически)</span>
            <?php endif; ?>
        </li>
        <?php if ($fetchedAt !== ''): ?>
            <li>Список подписчиков ВК: обновлён <?= $e($fetchedAt) ?>
                <span class="res-meta">(кеш, обновляется раз в сутки)</span></li>
        <?php endif; ?>
    </ul>

    <?php if ($lastError !== ''): ?>
        <div class="res-flash res-flash--error">Последняя ошибка: <?= $e($lastError) ?></div>
    <?php endif; ?>

    <?php if ($configured): ?>
        <p>
            <a class="res-btn" href="<?= $e($authUrl) ?>">Подключить доступ к стене ВК</a>
        </p>
        <p class="res-meta">Откроется страница ВКонтакте: войдите под аккаунтом,
        который состоит в группе «Вече», и разрешите доступ. После подтверждения
        вы вернётесь сюда.</p>
    <?php endif; ?>
</section>

<?php if ($expiresAt !== ''): ?>
<section>
    <h2>Отключить</h2>
    <form class="res-form" method="post" action="/poselenie/vk-vechesk/otklyuchit">
        <?= Csrf::field() ?>
        <p class="res-meta">Сохранённый доступ будет забыт; пересылка постов
        остановится до повторного подключения.</p>
        <button class="res-btn res-btn--ghost" type="submit">Отключить доступ</button>
    </form>
</section>
<?php endif; ?>
