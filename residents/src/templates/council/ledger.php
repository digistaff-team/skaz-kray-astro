<?php use SkazResidents\{View, Csrf}; /** @var array $incomeCats */ /** @var array $expenseCats */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Бухгалтерия Общего дома</h1>
    <p class="res-meta">Вносите приход и расход по статьям. Жители видят эти же цифры в разделе «Бюджет Общего дома» (только просмотр).</p>
</section>

<div class="ledger-forms">
    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--green)">+ Приход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="income">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($incomeCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="ledger-amount-row">
                <label class="ledger-amount">Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="42000"></label>
                <label class="ledger-date">Дата <input type="date" name="entry_date"></label>
            </div>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Взносы за август"></label>
            <button type="submit" class="res-btn">Добавить приход</button>
        </form>
    </details>

    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--ochre)">− Расход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="expense">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($expenseCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="ledger-amount-row">
                <label class="ledger-amount">Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="12400"></label>
                <label class="ledger-date">Дата <input type="date" name="entry_date"></label>
            </div>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Замена автомата на щитке"></label>
            <label class="sovet-addlink">+ Добавить фото чека
                <input type="file" name="receipt" id="receiptPhotos" accept="image/*" hidden>
            </label>
            <div id="receiptPhotoPreview" class="photo-preview"></div>
            <button type="submit" class="res-btn">Добавить расход</button>
        </form>
    </details>
</div>

<?php require __DIR__ . '/../partials/ledger_report.php'; ?>

<script>
// Превью чека так же, как в форме задачи: скрытый input + ссылка + миниатюра
// с крестиком. Файл один (контроллер читает $_FILES['receipt']), поэтому
// DataTransfer-массив не нужен — достаточно показать и сбросить выбор.
(function () {
    var inp = document.getElementById('receiptPhotos'), box = document.getElementById('receiptPhotoPreview');
    if (!inp || !box) { return; }
    function render() {
        box.innerHTML = '';
        var f = inp.files && inp.files[0];
        if (!f) { return; }
        var wrap = document.createElement('span'); wrap.className = 'photo-uploaded';
        var img = document.createElement('img'); img.className = 'photo-thumb'; img.alt = '';
        img.src = URL.createObjectURL(f);
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'photo-del'; btn.textContent = '×'; btn.title = 'Убрать';
        btn.addEventListener('click', function () { inp.value = ''; render(); });
        wrap.appendChild(img); wrap.appendChild(btn); box.appendChild(wrap);
    }
    inp.addEventListener('change', render);
})();
</script>
