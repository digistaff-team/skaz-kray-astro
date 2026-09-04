<?php
use SkazResidents\View;
use SkazResidents\Csrf;
/** @var array $report */ /** @var bool $editable */ /** @var string $basePath */ /** @var string $uploadsUrl */
$fmt = static fn(float $n): string => number_format(abs($n), 0, '.', ' ') . ' ₽';
$sign = static fn(float $n): string => ($n < 0 ? '−' : '+') . number_format(abs($n), 0, '.', ' ') . ' ₽';
$editCats = $editable ? ['income' => $incomeCats ?? [], 'expense' => $expenseCats ?? []] : ['income' => [], 'expense' => []];
?>
<?php if (!$report['months']): ?>
    <p class="ledger-empty">Пока нет ни одной операции. Данные появятся здесь, как только совет внесёт первые приходы и расходы.</p>
<?php else: ?>

<div class="ledger-tiles">
    <div class="ledger-tile ledger-tile--in"><div class="label">Доход, <?= View::e($report['selectedLabel']) ?></div><div class="val"><?= View::e($fmt($report['monthIncome'] ?? 0.0)) ?></div></div>
    <div class="ledger-tile ledger-tile--out"><div class="label">Расходы, <?= View::e($report['selectedLabel']) ?></div><div class="val"><?= View::e($fmt($report['monthExpense'] ?? 0.0)) ?></div></div>
    <?php $mb = $report['monthBalance'] ?? 0.0; ?>
    <div class="ledger-tile ledger-tile--bal <?= $mb < 0 ? 'neg' : '' ?>"><div class="label">Остаток месяца</div><div class="val"><?= View::e($sign($mb)) ?></div></div>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .4rem">Помесячно</h2>
    <p class="ledger-hint">Доход поступает ежемесячно. В отдельные месяцы крупные траты превышают доход — остаток месяца уходит в минус, это нормально.</p>
    <div class="months-scroll">
    <table class="months">
        <thead><tr><th>Месяц</th><th>Доход</th><th>Расходы</th><th>Остаток месяца</th></tr></thead>
        <tbody>
            <?php foreach ($report['months'] as $m): ?>
                <tr>
                    <td class="m-name">
                        <a class="<?= $m['ym'] === $report['selectedYm'] ? 'active' : '' ?>" href="<?= View::e($basePath) ?>?mesyac=<?= View::e($m['ym']) ?>"><?= View::e($m['label']) ?></a>
                    </td>
                    <td class="m-in"><?= View::e($fmt($m['income'])) ?></td>
                    <td class="m-out"><?= View::e($fmt($m['expense'])) ?></td>
                    <td class="m-bal <?= $m['balance'] < 0 ? 'neg' : 'pos' ?>"><?= View::e($sign($m['balance'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Итого за всё время</td>
                <td class="m-in"><?= View::e($fmt($report['totalIncome'])) ?></td>
                <td class="m-out"><?= View::e($fmt($report['totalExpense'])) ?></td>
                <td class="m-bal <?= $report['totalBalance'] < 0 ? 'neg' : 'pos' ?>"><?= View::e($sign($report['totalBalance'])) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .2rem">Расходы по статьям — <?= View::e($report['selectedLabel']) ?></h2>
    <?php if (!$report['breakdown']): ?>
        <p class="res-meta">В этом месяце расходов не было.</p>
    <?php else: ?>
        <ul class="bars">
            <?php foreach ($report['breakdown'] as $b): ?>
                <li class="bar">
                    <div class="bar-top"><span class="bar-name"><?= View::e($b['name']) ?></span><span class="bar-sum"><?= View::e($fmt($b['sum'])) ?><span class="bar-pct"><?= (int) $b['pct'] ?>%</span></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= (int) $b['pct'] ?>%"></div></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .2rem">Операции — <?= View::e($report['selectedLabel']) ?></h2>
    <?php if (!$report['operations']): ?>
        <p class="res-meta">Операций за этот месяц нет.</p>
    <?php else: ?>
        <ul class="ops">
            <?php foreach ($report['operations'] as $op): ?>
                <?php $isOut = $op['kind'] === 'expense'; $d = (string) $op['entry_date']; ?>
                <li>
                    <div class="op-row">
                        <span class="op-date"><?= View::e(substr($d, 8, 2) . '.' . substr($d, 5, 2)) ?></span>
                        <span><span class="op-cat"><?= View::e($op['category']) ?></span><?= View::e($op['note']) ?></span>
                        <span class="op-sum <?= $isOut ? 'op-sum--out' : 'op-sum--in' ?>">
                            <?= $isOut ? '−' : '+' ?><?= View::e($fmt($op['amount'])) ?>
                            <?php if ($op['hasReceipt']): ?><a class="op-doc" href="<?= View::e($uploadsUrl) ?>/<?= View::e($op['receiptPath'] ?? '') ?>" target="_blank" rel="noopener">чек</a><?php endif; ?>
                        </span>
                    </div>
                    <?php if ($editable): ?>
                        <details class="ledger-op-edit">
                            <summary>Изменить / удалить</summary>
                            <form method="post" action="<?= View::e($basePath) ?>/operaciya/<?= (int) $op['id'] ?>/obnovit" class="res-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm']) ?>">
                                <?php
                                    $catList = $editCats[$op['kind']];
                                    $hasCurrent = false;
                                    foreach ($catList as $c) { if ((int) $c['id'] === $op['categoryId']) { $hasCurrent = true; break; } }
                                ?>
                                <label>Статья
                                    <select name="category_id">
                                        <?php if (!$hasCurrent): ?>
                                            <option value="<?= (int) $op['categoryId'] ?>" selected><?= View::e($op['category']) ?> (архив)</option>
                                        <?php endif; ?>
                                        <?php foreach ($catList as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $op['categoryId'] ? 'selected' : '' ?>><?= View::e($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Сумма, ₽ <input type="text" name="amount" value="<?= View::e(number_format($op['amount'], 2, '.', '')) ?>"></label>
                                <label>Дата <input type="date" name="entry_date" value="<?= View::e($d) ?>"></label>
                                <label>Описание <input type="text" name="note" value="<?= View::e($op['note']) ?>" maxlength="300"></label>
                                <button type="submit" class="res-btn">Сохранить</button>
                            </form>
                            <form method="post" action="<?= View::e($basePath) ?>/operaciya/<?= (int) $op['id'] ?>/udalit" onsubmit="return confirm('Удалить операцию?')" style="margin-top:.5rem">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm']) ?>">
                                <button type="submit" class="res-link-btn sovet-danger">Удалить операцию</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php endif; ?>
