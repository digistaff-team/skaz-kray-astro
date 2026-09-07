<?php use SkazResidents\{View, CouncilData}; ?>
<section class="sovet-hero">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Попечительский совет Общего дома</h1>
    <p class="sovet-lead">Положение, протоколы и правила, состав совета, ближайшее собрание и живой список текущих задач по содержанию Сказочного Терема.</p>
</section>

<?php if (!empty($me)): ?>
    <p class="sovet-whoami">Вы вошли как <strong><?= View::e($me['name']) ?></strong> — <?= View::e(CouncilData::roleLabel($me)) ?></p>
<?php endif; ?>

<div class="sovet-actions">
    <a class="res-btn sovet-action" href="/sovet/zadachi"><span>Текущие задачи</span><span class="sovet-action-n"><?= (int) $activeCount ?></span></a>
    <a class="res-btn res-btn--ghost sovet-action" href="/sovet/napravleniya"><span>Направления работы</span><span class="sovet-action-n"><?= (int) $directionsCount ?></span></a>
</div>

<div class="res-card">
    <div class="sovet-card-head">
        <h2>Ближайшее собрание</h2>
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
    <h3 class="sovet-h3">Повестка</h3>
    <ol class="sovet-agenda">
        <?php foreach ($nextMeeting['agenda'] as $item): ?>
            <li><?= View::e($item) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($canEditMeeting) && !empty($dutyCandidates)): ?>
        <form class="res-form sovet-handoff" method="post" action="/sovet/dezhurstvo/peredat">
            <?= \SkazResidents\Csrf::field() ?>
            <label>Передать роль дежурного
                <select name="member_id" required>
                    <option value="">— выберите члена совета —</option>
                    <?php foreach ($dutyCandidates as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="res-btn" type="submit">Передать дежурство</button>
        </form>
    <?php endif; ?>
</div>

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
    <h2>Протоколы встреч</h2>
    <ul class="sovet-doclist">
        <?php foreach ($protocols as $p): ?>
            <li><a href="<?= View::e($p['href']) ?>" target="_blank" rel="noopener"><?= View::e($p['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="res-card">
    <h2>Состав Попечительского совета</h2>
    <ul class="sovet-roster">
        <?php foreach ($members as $m): ?>
            <li><span class="sovet-roster-name"><?= View::e($m['name']) ?></span></li>
        <?php endforeach; ?>
    </ul>
</div>
