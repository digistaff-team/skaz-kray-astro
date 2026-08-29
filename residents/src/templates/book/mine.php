<?php
use SkazResidents\{Csrf, View};
$label = fn(string $s) => ['available' => 'свободна', 'on_loan' => 'на руках', 'maintenance' => 'недоступна', 'hidden' => 'скрыта'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
$loanLabel = fn(string $s) => ['requested' => 'ожидает решения', 'on_loan' => 'на руках', 'returned' => 'возвращена', 'declined' => 'отклонена', 'cancelled' => 'отменена'][$s] ?? $s;
?>
<h1>Мои книги</h1>
<p class="res-meta"><a class="res-btn" href="/poselenie/knigi/novaya">+ Поделиться книгой</a> <a class="res-btn res-btn--ghost" href="/poselenie/knigi">В каталог</a></p>

<section>
    <h2>Я делюсь</h2>
    <?php if (!$books): ?><p class="res-meta">Вы пока не добавили книг.</p><?php endif; ?>
    <?php foreach ($books as $b): $id = (int) $b['id']; ?>
        <div class="res-card tool-mine-row">
            <div>
                <a href="/poselenie/knigi/<?= $id ?>"><strong><?= View::e($b['title']) ?></strong></a>
                <?php if ($b['author'] !== ''): ?><span class="res-meta"><?= View::e($b['author']) ?></span><?php endif; ?>
                <span class="tool-st <?= $cls($b['status']) ?>"><?= $label($b['status']) ?></span>
                <?php if ($b['status'] === 'on_loan' && !empty($b['holder'])): ?><span class="res-meta">у: <?= View::e($b['holder']) ?></span><?php endif; ?>
            </div>
            <div class="tool-mine-actions">
                <a href="/poselenie/knigi/<?= $id ?>/redaktirovat">Редактировать</a>
                <?php if ($b['status'] !== 'on_loan'): ?>
                    <form method="post" action="/poselenie/knigi/<?= $id ?>/nedostupna">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit"><?= $b['status'] === 'maintenance' ? 'Готова к выдаче' : 'Недоступна' ?></button>
                    </form>
                    <form method="post" action="/poselenie/knigi/<?= $id ?>/skryt">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit"><?= $b['status'] === 'hidden' ? 'Показать' : 'Скрыть' ?></button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/poselenie/knigi/<?= $id ?>/udalit" onsubmit="return confirm('Удалить книгу?')">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Брони на мои книги</h2>
    <?php
        $pending = array_filter($incoming, fn($l) => $l['status'] === 'requested');
        $onloan  = array_filter($incoming, fn($l) => $l['status'] === 'on_loan');
    ?>
    <?php if (!$pending && !$onloan): ?><p class="res-meta">Активных броней нет.</p><?php endif; ?>

    <?php foreach ($pending as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <strong><?= View::e($l['book_title']) ?></strong> — бронь от <?= View::e($l['borrower_name']) ?>
            <?php if (!empty($l['due_date'])): ?><span class="res-meta">до <?= View::e($l['due_date']) ?></span><?php endif; ?>
            <?php if (!empty($l['message'])): ?><p><?= nl2br(View::e($l['message'])) ?></p><?php endif; ?>
            <div class="tool-mine-actions">
                <form method="post" action="/poselenie/knigi-bron/<?= $lid ?>/vydat">
                    <?= Csrf::field() ?><button class="res-btn" type="submit">Выдать</button>
                </form>
                <form method="post" action="/poselenie/knigi-bron/<?= $lid ?>/otklonit">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Отклонить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($onloan as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <strong><?= View::e($l['book_title']) ?></strong> — на руках у <?= View::e($l['borrower_name']) ?>
            <?php if (!empty($l['due_date'])): ?><span class="res-meta">до <?= View::e($l['due_date']) ?></span><?php endif; ?>
            <details class="tool-return">
                <summary class="res-btn res-btn--ghost">Принять возврат</summary>
                <form class="res-form" method="post" action="/poselenie/knigi-bron/<?= $lid ?>/vozvrat">
                    <?= Csrf::field() ?>
                    <label>Состояние при возврате
                        <select name="condition">
                            <option value="ok">в порядке</option>
                            <option value="broken">повреждена → недоступна</option>
                        </select>
                    </label>
                    <label>Заметка (необязательно)
                        <input type="text" name="note" maxlength="500">
                    </label>
                    <button class="res-btn" type="submit">Подтвердить возврат</button>
                </form>
            </details>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Я взял(а) почитать</h2>
    <?php if (!$borrowings): ?><p class="res-meta">Вы пока ничего не брали.</p><?php endif; ?>
    <?php foreach ($borrowings as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <a href="/poselenie/knigi/<?= (int) $l['book_id'] ?>"><strong><?= View::e($l['book_title']) ?></strong></a>
            <span class="tool-loan-st"><?= $loanLabel($l['status']) ?></span>
            <span class="res-meta">владелец: <?= View::e($l['owner_name']) ?><?php if (!empty($l['due_date'])): ?> · до <?= View::e($l['due_date']) ?><?php endif; ?></span>
            <?php if ($l['status'] === 'requested'): ?>
                <form method="post" action="/poselenie/knigi-bron/<?= $lid ?>/otmenit" style="display:inline">
                    <?= Csrf::field() ?><button class="res-link-btn" type="submit">Отменить бронь</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
