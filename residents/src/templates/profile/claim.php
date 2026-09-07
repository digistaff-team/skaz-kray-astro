<?php
use SkazResidents\View;
/** @var array $households */
// Имя поляны без номера: «(1) Обережная» -> «Обережная». Иначе — как есть.
$gladeName = static function (string $g): string {
    $g = trim($g);
    if (preg_match('~^\(\d+\)\s*(.+)$~u', $g, $m)) { return trim($m[1]); }
    return $g !== '' ? $g : 'Без поляны';
};
// Номер поляны из «(N) …» (для сортировки и подписи участка); без номера — в конец.
$gladeNum = static function (string $g): int {
    return preg_match('~^\((\d+)\)~u', trim($g), $m) ? (int) $m[1] : 999;
};
// Номер участка (первое число из «Уч.»); без числа — в конец.
$plotNum = static function (string $p): int {
    return preg_match('~\d+~', trim($p), $m) ? (int) $m[0] : 9999;
};
// Группируем поместья по полянам, сохраняя первое встреченное написание; ключ
// нормализуем (регистр + пробелы), чтобы разные написания одной поляны не двоились.
$byGlade = [];
foreach ($households as $h) {
    $key = mb_strtolower(preg_replace('~\s+~u', ' ', trim($h['glade'])));
    if (!isset($byGlade[$key])) { $byGlade[$key] = ['name' => $h['glade'], 'hhs' => []]; }
    $byGlade[$key]['hhs'][] = $h;
}
foreach ($byGlade as &$grp) {
    usort($grp['hhs'], static fn(array $a, array $b): int => $plotNum((string) $a['plot']) <=> $plotNum((string) $b['plot']));
}
unset($grp);
uasort($byGlade, static fn(array $a, array $b): int => $gladeNum($a['name']) <=> $gladeNum($b['name']));
?>
<h1>Выберите ваше поместье</h1>
<p class="res-meta">Найдите своё поместье в списке и привяжите его к себе по фамилии — после этого вы сможете проверить и исправить данные в профиле своего поместья. Привязка делается один раз.</p>

<?php if (!$households): ?>
    <p class="res-meta">Свободных для привязки поместий нет. Обратитесь к редактору поселения.</p>
<?php endif; ?>

<div class="res-dir">
    <?php foreach ($byGlade as $grp): ?>
        <?php $hhs = $grp['hhs']; $gnum = $gladeNum($grp['name']); ?>
        <section class="res-glade">
            <button type="button" class="res-glade-head" aria-expanded="false">
                <span class="res-glade-name">Поляна <?= View::e($gladeName($grp['name'])) ?> (<?= $gnum ?>)</span>
                <span class="res-hh-count"><?= count($hhs) ?> <?= View::e(plural_ru(count($hhs), 'участок', 'участка', 'участков')) ?></span>
                <span class="res-hh-chevron" aria-hidden="true"></span>
            </button>
            <div class="res-glade-body">
                <?php foreach ($hhs as $h): ?>
                    <?php
                    $occupied = (int) $h['occupied'] === 1;               // привязан к активному аккаунту
                    $hasResidents = (int) $h['member_count'] > 0;          // есть жители для подтверждения фамилией
                    // Открыть можно любой участок с жителями: свободный — привязать первичным
                    // владельцем; занятый — присоединиться совладельцем (совместное владение).
                    // Без жителей нечем подтвердить принадлежность — не открываем.
                    $clickable = $hasResidents;
                    $cls = 'prof-card ' . ($occupied ? 'prof-card--taken' : 'prof-card--free');
                    $name = $h['estate_name'] !== '' ? View::e($h['estate_name']) : 'Поместье';
                    $plotMeta = $h['plot'] !== '' ? 'участок ' . $gnum . '-' . View::e($h['plot']) : '';
                    ?>
                    <?php if ($clickable): ?>
                        <a class="<?= $cls ?>" href="/poselenie/moye-pomestie/vybor/<?= (int) $h['id'] ?>">
                            <b class="prof-name"><?= $name ?></b>
                            <?php if ($plotMeta !== ''): ?><div class="res-meta"><?= $plotMeta ?></div><?php endif; ?>
                            <?php if ($occupied): ?><div class="res-meta">Уже с семьёй — можно присоединиться</div><?php endif; ?>
                        </a>
                    <?php else: ?>
                        <div class="<?= $cls ?>">
                            <b class="prof-name"><?= $name ?></b>
                            <?php if ($plotMeta !== ''): ?><div class="res-meta"><?= $plotMeta ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script>
(function () {
  Array.prototype.forEach.call(document.querySelectorAll('.res-glade-head'), function (btn) {
    btn.addEventListener('click', function () {
      var open = btn.closest('.res-glade').classList.toggle('res-glade--open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
})();
</script>
