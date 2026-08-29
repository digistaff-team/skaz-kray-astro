<?php
use SkazResidents\{Csrf, View};
$tripLabel = fn(string $s) => ['active' => 'актуальна', 'done' => 'состоялась', 'cancelled' => 'отменена'][$s] ?? $s;
$bkLabel = fn(string $s) => ['requested' => 'ожидает подтверждения', 'confirmed' => 'подтверждена', 'declined' => 'отклонена', 'cancelled' => 'отменена'][$s] ?? $s;
?>
<p class="res-meta"><a href="/poselenie/poezdki">← Ко всем поездкам</a></p>
<div class="tool-show-head">
    <h1><?= View::e($trip['origin']) ?> <span class="trip-arrow">→</span> <?= View::e($trip['destination']) ?></h1>
    <span class="tool-st <?= $trip['status'] === 'active' ? 'tool-st--free' : ($trip['status'] === 'done' ? 'tool-st--loan' : 'tool-st--maint') ?>"><?= $tripLabel($trip['status']) ?></span>
</div>

<div class="res-card">
    <p><strong>Когда:</strong> <?= View::e(ru_date((string) $trip['trip_date'])) ?><?php if (!empty($trip['trip_time'])): ?>, <?= View::e($trip['trip_time']) ?><?php endif; ?></p>
    <p><strong>Водитель:</strong> <?= View::e($trip['driver_name']) ?></p>
    <p><strong>Свободно мест:</strong> <?= (int) $trip['seats_free'] ?> из <?= (int) $trip['seats_total'] ?></p>
    <?php if (!empty($trip['note'])): ?><p><?= nl2br(View::e($trip['note'])) ?></p><?php endif; ?>
</div>

<?php if ($isDriver): ?>
    <div class="res-card">
        <p class="res-meta">Это ваша поездка. Управление и брони — в разделе <a href="/poselenie/poezdki/moi">«Мои поездки»</a>.</p>
    </div>
    <?php if ($bookings): ?>
        <div class="res-card">
            <h2>Брони</h2>
            <ul class="tool-history">
                <?php foreach ($bookings as $b): ?>
                    <li>
                        <span class="tool-loan-st"><?= $bkLabel($b['status']) ?></span>
                        <?= View::e($b['passenger_name']) ?> · <?= (int) $b['seats'] ?> место(а)
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php elseif ($myBooking): ?>
    <div class="res-card">
        <p>Ваша бронь: <span class="tool-loan-st"><?= $bkLabel($myBooking['status']) ?></span> · <?= (int) $myBooking['seats'] ?> место(а).</p>
        <p class="res-meta">Управление бронью — в разделе <a href="/poselenie/poezdki/moi">«Мои поездки»</a>.</p>
    </div>
<?php elseif ($canBook): ?>
    <div class="res-card">
        <h2>Забронировать место</h2>
        <form class="res-form" method="post" action="/poselenie/poezdki/<?= (int) $trip['id'] ?>/bron">
            <?= Csrf::field() ?>
            <label>Сколько мест
                <input type="number" name="seats" min="1" max="<?= (int) $maxSeats ?>" value="1" required>
            </label>
            <label>Сообщение водителю (необязательно)
                <textarea name="message" placeholder="Где удобно сесть, во сколько…"></textarea>
            </label>
            <button class="res-btn" type="submit">Забронировать</button>
        </form>
    </div>
<?php elseif ($trip['status'] !== 'active' || (int) $trip['seats_free'] <= 0): ?>
    <div class="res-card"><p class="res-meta">Поездка недоступна для брони (<?= $tripLabel($trip['status']) ?><?= (int) $trip['seats_free'] <= 0 ? ', мест нет' : '' ?>).</p></div>
<?php endif; ?>
