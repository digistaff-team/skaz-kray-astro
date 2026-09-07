<?php use SkazResidents\{View, CouncilData}; ?>
<section class="sovet-hero">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Попечительский совет Общего дома</h1>
    <p class="sovet-lead">Положение, протоколы и правила, состав совета, ближайшее собрание и живой список текущих задач.</p>
</section>

<?php if (!empty($me)): ?>
    <p class="sovet-whoami">Вы вошли как <strong><?= View::e($me['name']) ?></strong> — <?= View::e(CouncilData::roleLabel($me)) ?></p>
<?php endif; ?>

<div class="res-card">
    <div class="sovet-card-head">
        <h2>Встреча Совета</h2>
        <?php if (!empty($canEditMeeting)): ?>
            <a class="res-link-btn" href="/sovet/vstrecha">Редактировать</a>
        <?php endif; ?>
    </div>
    <p class="sovet-meet-date"><?= View::e($nextMeeting['date']) ?></p>
    <p class="res-meta"><?= View::e($nextMeeting['place']) ?></p>
    <p class="res-meta">
        Дежурный председатель: <strong><?= View::e($nextMeeting['dutyChair']) ?></strong><br>
        Дежурный секретарь: <strong><?= View::e($nextMeeting['dutySecretary']) ?></strong>
    </p>
    <p><button type="button" id="agenda-open" class="sovet-agenda-trigger">Повестка встречи</button></p>

    <dialog id="agenda-dialog" class="sovet-dialog">
        <h3 class="sovet-h3">Повестка встречи</h3>
        <?php if (empty($agendaItems)): ?>
            <p class="res-meta">Пунктов пока нет. Добавьте свой на странице повестки.</p>
        <?php else: ?>
            <div class="sovet-agenda">
                <?php foreach ($agendaItems as $it): ?>
                    <p<?= !empty($it['discussed']) ? ' class="is-discussed"' : '' ?>><?= View::e($it['title']) ?><?php if ($it['author'] !== ''): ?> <span class="sovet-agenda-author">— <?= View::e($it['author']) ?></span><?php endif; ?><?php if (!empty($it['carried_over'])): ?> <span class="sovet-agenda-carried">с прошлой встречи</span><?php endif; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="sovet-dialog-actions">
            <a class="res-btn" href="/sovet/povestka">Добавить / изменить пункты</a>
            <form method="dialog" style="display:inline;"><button class="res-btn res-btn--ghost" type="submit">Закрыть</button></form>
        </div>
    </dialog>
    <script>
    (function () {
        var btn = document.getElementById('agenda-open');
        var dlg = document.getElementById('agenda-dialog');
        if (!btn || !dlg || !dlg.showModal) { return; }
        btn.addEventListener('click', function () { dlg.showModal(); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    })();
    </script>
</div>

<div class="sovet-actions">
    <a class="res-btn sovet-action" href="/sovet/napravleniya"><span>Направления работы</span><span class="sovet-action-n"><?= (int) $directionsCount ?></span></a>
    <a class="res-btn res-btn--ghost sovet-action" href="/sovet/zadachi"><span>Текущие задачи</span><span class="sovet-action-n"><?= (int) $activeCount ?></span></a>
</div>

<details class="res-card sovet-acc">
    <summary><h2>Документы</h2></summary>
    <ul class="sovet-doclist">
        <?php foreach ($documents as $d): ?>
            <li>
                <a href="<?= View::e($d['href']) ?>" target="_blank" rel="noopener"><?= View::e($d['title']) ?></a>
            </li>
        <?php endforeach; ?>
        <li>
            <a href="/sovet/buhgalteriya">Бюджет Общего дома — приход, расход, остатки</a>
        </li>
    </ul>
</details>

<details class="res-card sovet-acc">
    <summary><h2>Протоколы встреч</h2></summary>
    <ul class="sovet-doclist">
        <?php foreach ($protocols as $p): ?>
            <li><a href="<?= View::e($p['href']) ?>" target="_blank" rel="noopener"><?= View::e($p['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</details>

<details class="res-card sovet-acc">
    <summary><h2>Состав Попечительского совета Общего дома</h2></summary>
    <ul class="sovet-roster sovet-roster--cols">
        <?php foreach ($members as $m): ?>
            <li><span class="sovet-roster-name"><?= View::e($m['name']) ?></span></li>
        <?php endforeach; ?>
    </ul>
</details>
