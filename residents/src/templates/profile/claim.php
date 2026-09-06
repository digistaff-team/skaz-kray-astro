<?php
use SkazResidents\View;
/** @var array $households @var string $q */
$gladeName = static function (string $g): string {
    return preg_match('~^\(\d+\)\s*(.+)$~u', trim($g), $m) ? trim($m[1]) : $g;
};
?>
<h1>Выберите ваше поместье</h1>
<p class="res-meta">Найдите своё поместье в списке и привяжите его к аккаунту — после этого вы сможете проверять и править данные своей семьи. Привязка делается один раз.</p>

<form class="tool-filters" method="get" action="/poselenie/moye-pomestie/vybor">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Поиск по фамилии, поляне или названию поместья">
    <button class="res-btn" type="submit">Найти</button>
    <?php if ($q !== ''): ?><a class="res-btn res-btn--ghost" href="/poselenie/moye-pomestie/vybor">Сбросить</a><?php endif; ?>
</form>

<?php if (!$households): ?>
    <p class="res-meta"><?= $q !== '' ? 'Ничего не найдено. Попробуйте другой запрос.' : 'Свободных для привязки поместий нет. Обратитесь к редактору поселения.' ?></p>
<?php endif; ?>

<div class="prof-list">
    <?php foreach ($households as $h): ?>
        <a class="prof-card prof-pick" href="/poselenie/moye-pomestie/vybor/<?= (int) $h['id'] ?>">
            <b class="prof-name"><?= $h['estate_name'] !== '' ? View::e($h['estate_name']) : 'Поместье' ?></b>
            <div class="res-meta">
                Поляна <?= View::e($gladeName($h['glade'])) ?><?php if ($h['plot'] !== ''): ?>, участок <?= View::e($h['plot']) ?><?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>
