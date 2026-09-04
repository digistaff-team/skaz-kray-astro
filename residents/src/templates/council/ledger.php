<?php use SkazResidents\{View, Csrf}; /** @var array $incomeCats */ /** @var array $expenseCats */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Бухгалтерия Общего дома</h1>
    <p>Вносите приход и расход по статьям. Жители видят эти же цифры в разделе «Бюджет Общего дома» (только просмотр).</p>
    <p class="sovet-hero-actions">
        <a class="res-btn res-btn--ghost" href="/sovet/buhgalteriya/statyi">Статьи бюджета</a>
    </p>
</section>

<div class="ledger-forms">
    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--green)">+ Добавить приход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="income">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($incomeCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="42000"></label>
            <label>Дата <input type="date" name="entry_date"></label>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Взносы за август"></label>
            <button type="submit" class="res-btn">Добавить приход</button>
        </form>
    </details>

    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--ochre)">− Добавить расход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="expense">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($expenseCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="12400"></label>
            <label>Дата <input type="date" name="entry_date"></label>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Замена автомата на щитке"></label>
            <label>Фото чека (необязательно) <input type="file" name="receipt" accept="image/*"></label>
            <button type="submit" class="res-btn">Добавить расход</button>
        </form>
    </details>
</div>

<?php require __DIR__ . '/../partials/ledger_report.php'; ?>
