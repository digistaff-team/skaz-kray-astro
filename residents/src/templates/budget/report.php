<?php /** @var array $report */ ?>
<?php $backFallback = '/poselenie/obshchiy-dom'; require __DIR__ . '/../partials/back.php'; ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Для жителей поселения</p>
    <h1>Бюджет Общего дома</h1>
    <p class="res-meta">Открытый помесячный отчёт о расходовании средств Фонда Общего дома</p>
</section>

<?php require __DIR__ . '/../partials/ledger_report.php'; ?>

<p class="res-meta" style="text-align:center;margin-top:1.5rem">Учёт расходования средств ведёт Попечительский совет Общего дома.</p>
