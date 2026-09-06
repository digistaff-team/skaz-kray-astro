<?php use SkazResidents\View; use SkazResidents\Sections; /** @var array $dash */ ?>
<div class="app-wrap">
  <div class="app-head">
    <img class="app-logo" src="/poselenie/assets/icons/icon-192.png" alt="" width="52" height="52">
    <div class="app-hello">
      <b>Сказочный Край</b>
      <span><?= View::e($me) ?></span>
    </div>
  </div>

  <?php $d = $dash['diary']; ?>
  <?php if ($d['count'] > 0): ?>
    <a class="app-diary" href="/poselenie/dnevniki">
      <b><?= View::e(diary_status_line($d)) ?></b>
      <span><?= View::e($d['latestTitle']) ?></span>
    </a>
  <?php else: ?>
    <a class="app-diary" href="/poselenie/dnevnik/novaya">
      <b>Дневник нашего поместья</b>
      <span>Добавьте первую запись</span>
    </a>
  <?php endif; ?>

  <?php if (!empty($dash['otherDiaries'])): ?>
    <div class="app-sec-label">Новое в дневниках соседей</div>
    <?php foreach ($dash['otherDiaries'] as $od): ?>
      <a class="app-diary app-diary--other" href="/poselenie/dnevniki/<?= (int) $od['id'] ?>">
        <b><?= View::e($od['family']) ?></b>
        <span><?= View::e($od['title']) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="app-grid" id="appGrid">
    <?php if (Sections::isEnabled('dnevniki')): ?><a class="app-tile" href="/poselenie/dnevniki"><b>Дневники<br>поместий</b><span>лента поселения</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('instrumenty')): ?><a class="app-tile" href="/poselenie/instrumenty"><b>Инструменты</b><span>свободно <?= (int) $dash['counts']['toolsFree'] ?></span></a><?php endif; ?>
    <?php if (Sections::isEnabled('knigi')): ?><a class="app-tile" href="/poselenie/knigi"><b>Книги</b><span>на полке <?= (int) $dash['counts']['books'] ?></span></a><?php endif; ?>
    <?php if (Sections::isEnabled('poezdki')): ?><a class="app-tile" href="/poselenie/poezdki"><b>Поездки</b><span><?= (int) $dash['counts']['trips'] ?> <?= View::e(plural_ru((int) $dash['counts']['trips'], 'поездка', 'поездки', 'поездок')) ?></span></a><?php endif; ?>
    <?php if (Sections::isEnabled('byudzhet')): ?><a class="app-tile" href="/poselenie/byudzhet"><b>Бюджет<br>Общего дома</b><span>отчёт о расходах</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('yarmarka')): ?><a class="app-tile" href="/poselenie/yarmarka"><b>Ярмарка</b><span>рынок поселения</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('sosedi')): ?><a class="app-tile" href="/poselenie/sosedi"><b>Наши<br>соседи</b><span>справочник поселения</span></a><?php endif; ?>
    <a class="app-tile" href="/poselenie/moye-pomestie"><b>Наше<br>поместье</b><span>личный кабинет семьи</span></a>
  </div>

  <div class="app-offline-banner" id="appOffline">
    <span class="app-offline-dot"></span>
    <span>Сети нет. Показываем сохранённое на <?= View::e($savedAt) ?>, изменения уйдут при связи.</span>
  </div>
</div>

<script>
(function () {
  var b = document.getElementById('appOffline');
  function sync() { if (!b) return; b.classList.toggle('show', !navigator.onLine); }
  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
})();

// Перестановка плиток длинным нажатием. Перетаскиваемая плитка плавно следует за
// пальцем (transform), цель подсвечивается, реальная перестановка — один раз при
// отпускании (без «скачков»). Порядок хранится на устройстве (localStorage).
(function () {
  var grid = document.getElementById('appGrid');
  if (!grid) return;
  var KEY = 'poselenie_tile_order_v1';
  var keyOf = function (t) { try { return new URL(t.href).pathname; } catch (e) { return t.getAttribute('href'); } };

  // Применяем сохранённый порядок; новые плитки остаются в конце.
  try {
    var saved = JSON.parse(localStorage.getItem(KEY) || '[]');
    if (saved.length) {
      var byKey = {};
      Array.prototype.forEach.call(grid.querySelectorAll('.app-tile'), function (t) { byKey[keyOf(t)] = t; });
      saved.forEach(function (k) { if (byKey[k]) { grid.appendChild(byKey[k]); delete byKey[k]; } });
    }
  } catch (e) {}

  var save = function () {
    try { localStorage.setItem(KEY, JSON.stringify(Array.prototype.map.call(grid.querySelectorAll('.app-tile'), keyOf))); } catch (e) {}
  };

  var timer = null, editing = false, drag = null, target = null;
  var px = 0, py = 0, moved = false, suppress = false, pid = null;

  var clearTarget = function () { if (target) { target.classList.remove('app-tile--target'); target = null; } };

  var begin = function (tile, e) {
    editing = true; drag = tile; pid = e.pointerId; px = e.clientX; py = e.clientY; moved = false;
    try { grid.setPointerCapture(pid); } catch (_) {}
    grid.classList.add('app-grid--editing');
    drag.classList.add('app-tile--drag');
    try { var sel = window.getSelection(); if (sel) sel.removeAllRanges(); } catch (_) {}
    if (navigator.vibrate) navigator.vibrate(12);
  };

  var finish = function () {
    if (timer) { clearTimeout(timer); timer = null; }
    if (editing && drag) {
      if (target && target !== drag && target.parentNode === grid) {
        var tiles = Array.prototype.slice.call(grid.querySelectorAll('.app-tile'));
        var di = tiles.indexOf(drag), oi = tiles.indexOf(target);
        grid.insertBefore(drag, oi > di ? target.nextSibling : target);
        save();
      }
      drag.style.transform = '';
      drag.classList.remove('app-tile--drag');
      clearTarget();
      grid.classList.remove('app-grid--editing');
      suppress = moved;
      setTimeout(function () { suppress = false; }, 80);
    }
    try { grid.releasePointerCapture(pid); } catch (_) {}
    editing = false; drag = null;
  };

  grid.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  grid.addEventListener('dragstart', function (e) { e.preventDefault(); });
  // Пока идёт жест — не даём начаться выделению текста нигде на странице.
  document.addEventListener('selectstart', function (e) { if (editing) e.preventDefault(); });

  grid.addEventListener('pointerdown', function (e) {
    var tile = e.target.closest && e.target.closest('.app-tile');
    if (!tile) return;
    px = e.clientX; py = e.clientY;
    timer = setTimeout(function () { timer = null; begin(tile, e); }, 300);
  });

  grid.addEventListener('pointermove', function (e) {
    // Небольшое движение до срабатывания долгого нажатия = это скролл/тап, отменяем.
    if (timer && (Math.abs(e.clientX - px) > 10 || Math.abs(e.clientY - py) > 10)) { clearTimeout(timer); timer = null; }
    if (!editing || !drag) return;
    e.preventDefault(); moved = true;
    drag.style.transform = 'translate(' + (e.clientX - px) + 'px,' + (e.clientY - py) + 'px) scale(1.06)';
    var el = document.elementFromPoint(e.clientX, e.clientY);
    var over = el && el.closest ? el.closest('.app-tile') : null;
    if (over && over !== drag && over.parentNode === grid) {
      if (over !== target) { clearTarget(); target = over; target.classList.add('app-tile--target'); }
    } else if (!over) {
      clearTarget();
    }
  });

  grid.addEventListener('pointerup', finish);
  grid.addEventListener('pointercancel', finish);
  // Если это было перетаскивание, гасим переход по ссылке.
  grid.addEventListener('click', function (e) { if (suppress) { e.preventDefault(); e.stopPropagation(); } }, true);
})();
</script>
