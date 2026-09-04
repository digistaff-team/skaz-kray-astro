<?php use SkazResidents\View; ?>
<section class="sovet-hero">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Попечительский совет Общего дома</h1>
    <p>Положение, протоколы и правила, состав совета, ближайшее собрание и живой список текущих задач по содержанию Сказочного Терема.</p>
    <p class="sovet-hero-actions">
        <a class="res-btn" href="/sovet/zadachi">Текущие задачи</a>
        <a class="res-btn res-btn--ghost" href="/sovet/napravleniya">Направления работы</a>
    </p>
</section>

<div class="sovet-cols">
    <div class="sovet-col-main">
        <div class="res-card">
            <h2>Документы</h2>
            <ul class="sovet-doclist">
                <?php foreach ($documents as $d): ?>
                    <li>
                        <a href="<?= View::e($d['href']) ?>" target="_blank" rel="noopener"><?= View::e($d['title']) ?></a>
                        <span class="sovet-kind"><?= View::e($d['kind']) ?></span>
                    </li>
                <?php endforeach; ?>
                <li>
                    <a href="/sovet/buhgalteriya">Бюджет Общего дома — приход, расход, остатки</a>
                    <span class="sovet-kind">Бухгалтерия</span>
                </li>
            </ul>
        </div>

        <div class="res-card">
            <h2>Протоколы собраний</h2>
            <ul class="sovet-doclist">
                <?php foreach ($protocols as $p): ?>
                    <li><a href="<?= View::e($p['href']) ?>" target="_blank" rel="noopener"><?= View::e($p['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <aside class="sovet-col-side">
        <div class="res-card">
            <h2>Ближайшее собрание</h2>
            <p class="sovet-meet-date"><?= View::e($nextMeeting['date']) ?></p>
            <p class="res-meta"><?= View::e($nextMeeting['place']) ?></p>
            <p class="res-meta">
                Дежурный председатель: <strong><?= View::e($nextMeeting['dutyChair']) ?></strong><br>
                Дежурный секретарь: <strong><?= View::e($nextMeeting['dutySecretary']) ?></strong>
            </p>
            <h3 class="sovet-h3">Повестка</h3>
            <ol class="sovet-agenda">
                <?php foreach ($nextMeeting['agenda'] as $item): ?>
                    <li><?= View::e($item) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>

        <div class="res-card">
            <h2>Состав совета</h2>
            <p class="res-meta">Председатель и секретарь — не постоянные должности: дежурная пара выбирается на каждой встрече.</p>
            <ul class="sovet-roster">
                <?php foreach ($members as $m): ?>
                    <li><span class="sovet-roster-name"><?= View::e($m['name']) ?></span><span class="sovet-roster-role"><?= View::e($m['role']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>
</div>
