<?php
use SkazResidents\{Csrf, View};
$tripLabel = fn(string $s) => ['active' => 'актуальна', 'done' => 'состоялась', 'cancelled' => 'отменена'][$s] ?? $s;
$bkLabel = fn(string $s) => ['requested' => 'ожидает подтверждения', 'confirmed' => 'подтверждена', 'declined' => 'отклонена', 'cancelled' => 'отменена'][$s] ?? $s;
$tripCls = fn(string $s) => 'tool-st--' . ($s === 'active' ? 'free' : ($s === 'done' ? 'loan' : 'maint'));
?>
<h1>Мои поездки</h1>
<p class="res-meta"><a class="res-btn" href="/poselenie/poezdki/novaya">+ Предложить поездку</a> <a class="res-btn res-btn--ghost" href="/poselenie/poezdki">Все поездки</a></p>

<section>
    <h2>Я за рулём</h2>
    <?php if (!$trips): ?><p class="res-meta">Вы пока не публиковали поездок.</p><?php endif; ?>
    <?php foreach ($trips as $t): $id = (int) $t['id']; ?>
        <div class="res-card tool-mine-row">
            <div>
                <a href="/poselenie/poezdki/<?= $id ?>"><strong><?= View::e($t['origin']) ?> → <?= View::e($t['destination']) ?></strong></a>
                <span class="tool-st <?= $tripCls($t['status']) ?>"><?= $tripLabel($t['status']) ?></span>
                <span class="res-meta"><?= View::e(ru_date((string) $t['trip_date'])) ?><?php if (!empty($t['trip_time'])): ?>, <?= View::e($t['trip_time']) ?><?php endif; ?> · <?= (int) $t['seats_free'] ?>/<?= (int) $t['seats_total'] ?> мест</span>
            </div>
            <div class="tool-mine-actions">
                <?php if ($t['status'] === 'active'): ?>
                    <form method="post" action="/poselenie/poezdki/<?= $id ?>/zavershit">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit">Состоялась</button>
                    </form>
                    <form method="post" action="/poselenie/poezdki/<?= $id ?>/otmenit">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit">Отменить</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/poselenie/poezdki/<?= $id ?>/udalit" onsubmit="return confirm('Удалить поездку?')">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Брони на мои поездки</h2>
    <?php
        $pending = array_filter($incoming, fn($b) => $b['status'] === 'requested');
        $confirmed = array_filter($incoming, fn($b) => $b['status'] === 'confirmed');
    ?>
    <?php if (!$pending && !$confirmed): ?><p class="res-meta">Активных броней нет.</p><?php endif; ?>

    <?php foreach ($pending as $b): $bid = (int) $b['id']; ?>
        <div class="res-card">
            <strong><?= View::e($b['origin']) ?> → <?= View::e($b['destination']) ?></strong>
            <span class="res-meta"><?= View::e(ru_date((string) $b['trip_date'])) ?></span> —
            бронь от <?= View::e($b['passenger_name']) ?> на <?= (int) $b['seats'] ?> место(а)
            <?php if (!empty($b['message'])): ?><p><?= nl2br(View::e($b['message'])) ?></p><?php endif; ?>
            <div class="tool-mine-actions">
                <form method="post" action="/poselenie/bron/<?= $bid ?>/podtverdit">
                    <?= Csrf::field() ?><button class="res-btn" type="submit">Подтвердить</button>
                </form>
                <form method="post" action="/poselenie/bron/<?= $bid ?>/otklonit">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Отклонить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($confirmed as $b): ?>
        <div class="res-card">
            <strong><?= View::e($b['origin']) ?> → <?= View::e($b['destination']) ?></strong>
            <span class="res-meta"><?= View::e(ru_date((string) $b['trip_date'])) ?></span> —
            <span class="tool-loan-st">подтверждена</span> <?= View::e($b['passenger_name']) ?> · <?= (int) $b['seats'] ?> место(а)
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Я пассажир</h2>
    <?php if (!$bookings): ?><p class="res-meta">Вы пока не бронировали поездок.</p><?php endif; ?>
    <?php foreach ($bookings as $b): $bid = (int) $b['id']; ?>
        <div class="res-card">
            <a href="/poselenie/poezdki/<?= (int) $b['trip_id'] ?>"><strong><?= View::e($b['origin']) ?> → <?= View::e($b['destination']) ?></strong></a>
            <span class="tool-loan-st"><?= $bkLabel($b['status']) ?></span>
            <span class="res-meta"><?= View::e(ru_date((string) $b['trip_date'])) ?><?php if (!empty($b['trip_time'])): ?>, <?= View::e($b['trip_time']) ?><?php endif; ?> · водитель: <?= View::e($b['driver_name']) ?> · <?= (int) $b['seats'] ?> место(а)</span>
            <?php if (in_array($b['status'], ['requested', 'confirmed'], true)): ?>
                <form method="post" action="/poselenie/bron/<?= $bid ?>/otmenit" style="display:inline">
                    <?= Csrf::field() ?><button class="res-link-btn" type="submit">Отменить бронь</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
