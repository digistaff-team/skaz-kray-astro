<?php
use SkazResidents\{Csrf, View};
$label = fn(string $s) => ['available' => 'свободен', 'on_loan' => 'на руках', 'maintenance' => 'на обслуживании', 'hidden' => 'скрыт'][$s] ?? $s;
$cls   = fn(string $s) => 'tool-st--' . ($s === 'available' ? 'free' : ($s === 'on_loan' ? 'loan' : ($s === 'maintenance' ? 'maint' : 'hidden')));
$loanLabel = fn(string $s) => ['requested' => 'ожидает решения', 'on_loan' => 'на руках', 'returned' => 'возвращён', 'declined' => 'отклонён', 'cancelled' => 'отменён'][$s] ?? $s;
?>
<h1>Мои инструменты</h1>
<p class="res-meta"><a class="res-btn" href="/poselenie/instrumenty/novyy">+ Поделиться инструментом</a> <a class="res-btn res-btn--ghost" href="/poselenie/instrumenty">В каталог</a></p>

<section>
    <h2>Я делюсь</h2>
    <?php if (!$tools): ?><p class="res-meta">Вы пока не добавили инструментов.</p><?php endif; ?>
    <?php foreach ($tools as $t): $id = (int) $t['id']; ?>
        <div class="res-card tool-mine-row">
            <div>
                <a href="/poselenie/instrumenty/<?= $id ?>"><strong><?= View::e($t['name']) ?></strong></a>
                <span class="tool-st <?= $cls($t['status']) ?>"><?= $label($t['status']) ?></span>
                <?php if ($t['status'] === 'on_loan' && !empty($t['holder'])): ?><span class="res-meta">у: <?= View::e($t['holder']) ?></span><?php endif; ?>
            </div>
            <div class="tool-mine-actions">
                <a href="/poselenie/instrumenty/<?= $id ?>/redaktirovat">Редактировать</a>
                <?php if ($t['status'] !== 'on_loan'): ?>
                    <form method="post" action="/poselenie/instrumenty/<?= $id ?>/remont">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit"><?= $t['status'] === 'maintenance' ? 'Готов к выдаче' : 'На обслуживание' ?></button>
                    </form>
                    <form method="post" action="/poselenie/instrumenty/<?= $id ?>/skryt">
                        <?= Csrf::field() ?><button class="res-link-btn" type="submit"><?= $t['status'] === 'hidden' ? 'Показать' : 'Скрыть' ?></button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/poselenie/instrumenty/<?= $id ?>/udalit" onsubmit="return confirm('Удалить инструмент?')">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Заявки на мои инструменты</h2>
    <?php
        $pending = array_filter($incoming, fn($l) => $l['status'] === 'requested');
        $onloan  = array_filter($incoming, fn($l) => $l['status'] === 'on_loan');
    ?>
    <?php if (!$pending && !$onloan): ?><p class="res-meta">Активных заявок нет.</p><?php endif; ?>

    <?php foreach ($pending as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <strong><?= View::e($l['tool_name']) ?></strong> — заявка от <?= View::e($l['borrower_name']) ?>
            <?php if (!empty($l['due_date'])): ?><span class="res-meta">до <?= View::e($l['due_date']) ?></span><?php endif; ?>
            <?php if (!empty($l['message'])): ?><p><?= nl2br(View::e($l['message'])) ?></p><?php endif; ?>
            <div class="tool-mine-actions">
                <form method="post" action="/poselenie/zaymy/<?= $lid ?>/vydat">
                    <?= Csrf::field() ?><button class="res-btn" type="submit">Выдать</button>
                </form>
                <form method="post" action="/poselenie/zaymy/<?= $lid ?>/otklonit">
                    <?= Csrf::field() ?><button class="res-link-btn sovet-danger" type="submit">Отклонить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($onloan as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <strong><?= View::e($l['tool_name']) ?></strong> — на руках у <?= View::e($l['borrower_name']) ?>
            <?php if (!empty($l['due_date'])): ?><span class="res-meta">до <?= View::e($l['due_date']) ?></span><?php endif; ?>
            <details class="tool-return">
                <summary class="res-btn res-btn--ghost">Принять возврат</summary>
                <form class="res-form" method="post" action="/poselenie/zaymy/<?= $lid ?>/vozvrat">
                    <?= Csrf::field() ?>
                    <label>Состояние при возврате
                        <select name="condition">
                            <option value="ok">исправен</option>
                            <option value="broken">неисправен → на обслуживание</option>
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
    <h2>Я взял(а) у соседей</h2>
    <?php $activeBor = array_filter($borrowings, fn($l) => in_array($l['status'], ['requested', 'on_loan'], true)); ?>
    <?php if (!$borrowings): ?><p class="res-meta">Вы пока ничего не брали.</p><?php endif; ?>
    <?php foreach ($borrowings as $l): $lid = (int) $l['id']; ?>
        <div class="res-card">
            <a href="/poselenie/instrumenty/<?= (int) $l['tool_id'] ?>"><strong><?= View::e($l['tool_name']) ?></strong></a>
            <span class="tool-loan-st"><?= $loanLabel($l['status']) ?></span>
            <span class="res-meta">владелец: <?= View::e($l['owner_name']) ?><?php if (!empty($l['due_date'])): ?> · до <?= View::e($l['due_date']) ?><?php endif; ?></span>
            <?php if ($l['status'] === 'requested'): ?>
                <form method="post" action="/poselenie/zaymy/<?= $lid ?>/otmenit" style="display:inline">
                    <?= Csrf::field() ?><button class="res-link-btn" type="submit">Отменить заявку</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
