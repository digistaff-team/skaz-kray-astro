<?php
use SkazResidents\View;
/**
 * Справочник «Наши соседи»: поляны → поместья → жители.
 *
 * $lazy — ленивый режим: отдаём только заголовки полян, поместья подгружаются
 * по клику с /poselenie/sosedi/polyana. Включается для мини-приложения MAX,
 * где разметка всего поселения разом (тысячи узлов) заметно тормозит webview.
 * При поиске лениться нечему: там и так раскрыто и найденного мало.
 *
 * @var array $glades @var array $stats @var string $q @var bool $lazy
 */
// Имя поляны без номера: «(1) Обережная» -> «Обережная». Иначе — как есть.
$gladeName = static function (string $g): string {
    $g = trim($g);
    if (preg_match('~^\(\d+\)\s*(.+)$~u', $g, $m)) { return trim($m[1]); }
    return $g !== '' ? $g : 'Без поляны';
};
?>
<?php $backFallback = '/poselenie/app'; require __DIR__ . '/../partials/back.php'; ?>
<div class="tool-head">
    <h1>Наши соседи</h1>
</div>
<p class="res-meta">Справочник для жителей поселения</p>

<form class="tool-filters" method="get" action="/poselenie/sosedi" role="search">
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Имя, фамилия, навык, поляна или город" aria-label="Поиск по соседям" enterkeyhint="search">
    <button class="res-btn" type="submit">Найти</button>
    <?php if ($q !== ''): ?><a class="res-btn res-btn--ghost" href="/poselenie/sosedi">Сбросить</a><?php endif; ?>
</form>

<?php if ($q !== '' && $glades): ?>
    <?php $found = array_sum(array_column($glades, 'count')); ?>
    <p class="res-meta">Найдено: <?= $found ?> <?= View::e(plural_ru($found, 'поместье', 'поместья', 'поместий')) ?></p>
<?php endif; ?>

<?php if (!$glades): ?>
    <p class="res-meta tool-empty">
        <?= $q !== '' ? 'Ничего не найдено. Попробуйте другой запрос.' : 'Справочник пока пуст.' ?>
    </p>
<?php endif; ?>

<div class="res-dir">
    <?php foreach ($glades as $grp): ?>
        <?php $gopen = $q !== ''; $gnum = $grp['num']; ?>
        <section class="res-glade<?= $gopen ? ' res-glade--open' : '' ?>">
            <button type="button" class="res-glade-head" aria-expanded="<?= $gopen ? 'true' : 'false' ?>">
                <span class="res-glade-name">Поляна <?= View::e($gladeName($grp['name'])) ?> (<?= $gnum ?>)</span>
                <span class="res-hh-count"><?= $grp['count'] ?> <?= View::e(plural_ru($grp['count'], 'участок', 'участка', 'участков')) ?></span>
                <span class="res-hh-chevron" aria-hidden="true"></span>
            </button>
            <div class="res-glade-body"<?= $lazy ? ' data-glade="' . View::e($grp['key']) . '"' : '' ?>>
                <?php if (!$lazy): ?>
                    <?php $hhs = $grp['hhs']; $open = $q !== ''; require __DIR__ . '/_glade_body.php'; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div id="photoLightbox" class="res-lightbox" hidden>
    <img class="res-lightbox-img" src="" alt="">
</div>

<script src="/poselenie/assets/tg-webapp.js?v=<?= asset_ver('assets/tg-webapp.js') ?>"></script>
<script>
(function () {
  var dir = document.querySelector('.res-dir');
  if (!dir) { return; }

  // Обработчики вешаем на весь справочник, а не на каждую кнопку: поместья
  // поляны могут приехать позже (ленивый режим), и подписывать их отдельно
  // пришлось бы после каждой загрузки.
  function toggle(btn, boxSel, openCls) {
    var open = btn.closest(boxSel).classList.toggle(openCls);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    return open;
  }

  /**
   * Поместья поляны по требованию. data-glade остаётся до конца загрузки и
   * снимается только при успехе: иначе повторное раскрытие ничего бы не
   * дозагрузило, и поляна осталась бы навсегда пустой после первой же
   * неудачной попытки.
   */
  function load(body) {
    var key = body.getAttribute('data-glade');
    if (!key || body.classList.contains('res-glade-body--loading')) { return; }
    body.classList.add('res-glade-body--loading');
    body.innerHTML = '<p class="res-meta">Открываем поляну…</p>';
    fetch('/poselenie/sosedi/polyana?p=' + encodeURIComponent(key), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        body.removeAttribute('data-glade');
      })
      .catch(function () {
        body.innerHTML = '<p class="res-meta">Не удалось открыть поляну. Проверьте связь и попробуйте снова.</p>';
      })
      .then(function () { body.classList.remove('res-glade-body--loading'); });
  }

  dir.addEventListener('click', function (e) {
    var gh = e.target.closest('.res-glade-head');
    if (gh && dir.contains(gh)) {
      var body = gh.closest('.res-glade').querySelector('.res-glade-body');
      if (toggle(gh, '.res-glade', 'res-glade--open') && body && body.hasAttribute('data-glade')) { load(body); }
      return;
    }
    var hh = e.target.closest('.res-hh-head');
    if (hh && dir.contains(hh)) { toggle(hh, '.res-hh', 'res-hh--open'); }
  });

  // Лайтбокс: миниатюра фото раскрывается на полный размер поверх страницы.
  // В мини-приложении обычная ссылка target=_blank не открывается — поэтому свой оверлей.
  var lb = document.getElementById('photoLightbox');
  var lbImg = lb && lb.querySelector('.res-lightbox-img');
  if (lb && lbImg) {
    var closeLb = function () { lb.hidden = true; lbImg.removeAttribute('src'); };
    dir.addEventListener('click', function (e) {
      var img = e.target.closest('.js-photo-full');
      if (!img || !dir.contains(img)) { return; }
      lbImg.src = img.getAttribute('data-full') || img.src;
      lb.hidden = false;
    });
    lb.addEventListener('click', closeLb);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lb.hidden) { closeLb(); } });
  }

  // Внутри Telegram Mini App ссылку t.me/username надо открывать через openTelegramLink
  // (обычная ссылка target=_blank в webview не открывается). Вне Telegram SkazTg сразу
  // отдаёт null и в сеть не ходит — ссылки остаются обычными.
  SkazTg.ensure(function (wa) {
    if (!wa || !wa.initData || !wa.openTelegramLink) { return; }
    dir.addEventListener('click', function (e) {
      var a = e.target.closest('.js-tg-link');
      if (!a || !dir.contains(a)) { return; }
      e.preventDefault();
      wa.openTelegramLink(a.href);
    });
  });
})();
</script>
