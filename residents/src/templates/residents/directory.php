<?php
use SkazResidents\View;
/** @var array $households @var array $stats @var string $q */

// Ссылка на VK: значение в таблице — «vk.com/xxx» или «id123»; приводим к полному URL.
$vkUrl = static function (string $v): string {
    $v = trim($v);
    if ($v === '') { return ''; }
    if (preg_match('~^https?://~i', $v)) { return $v; }
    return 'https://' . ltrim($v, '/');
};
$plural = static fn(int $n, string $a, string $b, string $c): string =>
    plural_ru($n, $a, $b, $c);
// Слова имени в нижнем регистре (для сличения ФИО жителя с именем Telegram-профиля).
$nameTokens = static function (string $s): array {
    $out = [];
    foreach (preg_split('~[\s]+~u', mb_strtolower(trim($s))) as $t) { if ($t !== '') { $out[] = $t; } }
    return $out;
};
// Оценка совпадения ФИО жителя ($res) с именем Telegram-профиля ($tg). Эвристика:
// точное слово = 2, совпадение по началу слова (≥3 симв.: Бобков/Бобкова, Александр/
// Саш — нет, Алекс/Александр — да) = 1. Чем выше, тем вероятнее это тот самый человек.
$nameScore = static function (array $res, array $tg): int {
    $s = 0;
    foreach ($tg as $t) {
        $best = 0;
        foreach ($res as $r) {
            if ($r === $t) { $best = 2; break; }
            if (mb_strlen($r) >= 3 && mb_strlen($t) >= 3
                && (mb_strpos($r, $t) === 0 || mb_strpos($t, $r) === 0)) { $best = 1; }
        }
        $s += $best;
    }
    return $s;
};
// Имя поляны без номера: «(1) Обережная» -> «Обережная». Иначе — как есть.
$gladeName = static function (string $g): string {
    $g = trim($g);
    if (preg_match('~^\(\d+\)\s*(.+)$~u', $g, $m)) { return trim($m[1]); }
    return $g !== '' ? $g : 'Без поляны';
};
// Группируем поместья по полянам, сохраняя порядок появления. Ключ нормализуем
// (регистр + пробелы), чтобы варианты написания одной поляны из разных таблиц
// («ЛюбоДарье»/«Любодарье») не двоились; отображаем первое встреченное написание.
$byGlade = [];
foreach ($households as $h) {
    $key = mb_strtolower(preg_replace('~\s+~u', ' ', trim($h['glade'])));
    if (!isset($byGlade[$key])) { $byGlade[$key] = ['name' => $h['glade'], 'hhs' => []]; }
    $byGlade[$key]['hhs'][] = $h;
}
// Внутри поляны участки — по номеру (первое число из «Уч.»; без числа — в конец).
$plotNum = static function (string $p): int {
    return preg_match('~\d+~', trim($p), $m) ? (int) $m[0] : 9999;
};
foreach ($byGlade as &$grp) {
    usort($grp['hhs'], static fn(array $a, array $b): int => $plotNum((string) $a['plot']) <=> $plotNum((string) $b['plot']));
}
unset($grp);
// Сортируем поляны по номеру в названии «(N) …» (без номера — в конец).
$gladeNum = static function (string $g): int {
    return preg_match('~^\((\d+)\)~u', trim($g), $m) ? (int) $m[1] : 999;
};
uasort($byGlade, static fn(array $a, array $b): int => $gladeNum($a['name']) <=> $gladeNum($b['name']));
?>
<a class="res-back" href="/poselenie/app">← На главную</a>
<div class="tool-head">
    <h1>Наши соседи</h1>
</div>
<p class="res-meta">Справочник для жителей поселения</p>

<?php if (!$households): ?>
    <p class="res-meta tool-empty">
        <?= $q !== '' ? 'Ничего не найдено. Попробуйте другой запрос.' : 'Справочник пока пуст.' ?>
    </p>
<?php endif; ?>

<div class="res-dir">
    <?php foreach ($byGlade as $grp): ?>
        <?php $hhs = $grp['hhs']; $gopen = $q !== ''; $gnum = $gladeNum($grp['name']); ?>
        <section class="res-glade<?= $gopen ? ' res-glade--open' : '' ?>">
            <button type="button" class="res-glade-head" aria-expanded="<?= $gopen ? 'true' : 'false' ?>">
                <span class="res-glade-name">Поляна <?= View::e($gladeName($grp['name'])) ?> (<?= $gnum ?>)</span>
                <span class="res-hh-count"><?= count($hhs) ?> <?= View::e($plural(count($hhs), 'участок', 'участка', 'участков')) ?></span>
                <span class="res-hh-chevron" aria-hidden="true"></span>
            </button>
            <div class="res-glade-body">
    <?php foreach ($hhs as $h): ?>
        <?php if (empty($h['people'])): /* свободный участок — статичная плашка, без раскрытия */ ?>
        <section class="res-hh res-hh--free">
            <div class="res-hh-head res-hh-head--static">
                <span class="res-hh-title">
                    <b class="res-hh-name"><?= View::e($h['estate_name'] !== '' ? $h['estate_name'] : 'Свободный участок') ?></b>
                    <span class="res-hh-meta"><?php if ($h['plot'] !== ''): ?>участок <?= $gnum ?>-<?= View::e($h['plot']) ?><?php endif; ?></span>
                </span>
            </div>
        </section>
        <?php continue; endif; ?>
        <?php $open = $q !== ''; ?>
        <section class="res-hh<?= $open ? ' res-hh--open' : '' ?>">
            <button type="button" class="res-hh-head" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                <span class="res-hh-title">
                    <b class="res-hh-name"><?= View::e($h['estate_name'] !== '' ? $h['estate_name'] : 'Поместье') ?></b>
                    <span class="res-hh-meta">
                        <?php $mp = []; if ($h['plot'] !== '') { $mp[] = 'участок ' . $gnum . '-' . $h['plot']; } ?>
                        <?= View::e(implode(' · ', $mp)) ?>
                    </span>
                </span>
                <span class="res-hh-count"><?= count($h['people']) ?> <?= View::e($plural(count($h['people']), 'житель', 'жителя', 'жителей')) ?></span>
                <span class="res-hh-chevron" aria-hidden="true"></span>
            </button>
            <ul class="res-people">
                <?php
                // «Tg» — только жителю, который авторизовался. Определяем его как жителя
                // с наибольшим совпадением ФИО с именем Telegram-профиля (эвристика
                // допускает неполное совпадение: Бобков/Бобкова, Алекс/Александр).
                $tgUser = (string) ($h['tg_username'] ?? '');
                $tgTokens = $nameTokens((string) ($h['tg_name'] ?? ''));
                $tgOwnerId = 0; $tgBest = 0;
                if ($tgUser !== '' && $tgTokens) {
                    foreach ($h['people'] as $pp) {
                        $sc = $nameScore($nameTokens((string) $pp['full_name']), $tgTokens);
                        if ($sc > $tgBest) { $tgBest = $sc; $tgOwnerId = (int) $pp['id']; }
                    }
                }
                ?>
                <?php foreach ($h['people'] as $p): ?>
                    <li class="res-person">
                        <div class="res-person-main">
                            <b><?= View::e($p['full_name']) ?></b>
                            <?php if ($p['birth_raw'] !== ''): ?><span class="res-meta">р. <?= View::e($p['birth_raw']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($p['skills'])): ?>
                            <div class="res-person-skills"><?= View::e($p['skills']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($p['community_role'])): ?>
                            <div class="res-meta">Для поселения: <?= View::e($p['community_role']) ?></div>
                        <?php endif; ?>
                        <?php
                        $where = $p['residence'] !== '' ? $p['residence'] : '';
                        if ($where === '' && $p['moved_text'] !== '') { $where = 'переехали ' . $p['moved_text']; }
                        ?>
                        <?php if ($p['hometown'] !== '' || $where !== ''): ?>
                            <div class="res-meta">
                                <?php if ($p['hometown'] !== ''): ?>родом из <?= View::e($p['hometown']) ?><?php endif; ?>
                                <?php if ($where !== ''): ?><?= $p['hometown'] !== '' ? ' · ' : '' ?><?= View::e($where) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="res-person-contacts">
                            <?php if ($p['phone'] !== ''): ?>
                                <a href="tel:<?= View::e(preg_replace('~[^\d+]~', '', $p['phone'])) ?>"><?= View::e($p['phone']) ?></a>
                            <?php endif; ?>
                            <?php if ($p['email'] !== ''): ?>
                                <a href="mailto:<?= View::e($p['email']) ?>"><?= View::e($p['email']) ?></a>
                            <?php endif; ?>
                            <?php if ($p['vk'] !== ''): ?>
                                <a href="<?= View::e($vkUrl($p['vk'])) ?>" target="_blank" rel="noopener">VK</a>
                            <?php endif; ?>
                            <?php if ($tgUser !== '' && $tgBest > 0 && (int) $p['id'] === $tgOwnerId): ?>
                                <a href="https://t.me/<?= View::e($tgUser) ?>" target="_blank" rel="noopener" class="js-tg-link">Tg</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!empty($h['cars'])): ?>
                    <li class="res-person res-cars">
                        <div class="res-meta"><b>Автомобили поместья:</b>
                            <?php foreach ($h['cars'] as $ci => $car): ?><?= $ci ? '; ' : ' ' ?><?= View::e($car['title']) ?><?php if ($car['plate'] !== ''): ?> (<?= View::e($car['plate']) ?>)<?php endif; ?><?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </section>
    <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
(function () {
  function bind(headSel, boxSel, openCls) {
    Array.prototype.forEach.call(document.querySelectorAll(headSel), function (btn) {
      btn.addEventListener('click', function () {
        var open = btn.closest(boxSel).classList.toggle(openCls);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
  }
  bind('.res-glade-head', '.res-glade', 'res-glade--open'); // поляна -> поместья
  bind('.res-hh-head', '.res-hh', 'res-hh--open');          // поместье -> жители

  // Внутри Telegram Mini App ссылку t.me/username надо открывать через openTelegramLink
  // (обычная ссылка target=_blank в webview не открывается). Вне Telegram — обычный переход.
  var wa = window.Telegram && window.Telegram.WebApp;
  if (wa && wa.initData && wa.openTelegramLink) {
    Array.prototype.forEach.call(document.querySelectorAll('.js-tg-link'), function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); wa.openTelegramLink(a.href); });
    });
  }
})();
</script>
