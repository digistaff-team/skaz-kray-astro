<?php use SkazResidents\View; use SkazResidents\Sections; /** @var array $dash */ ?>
<div class="app-wrap">
  <div class="app-head">
    <img class="app-logo" src="/poselenie/assets/icons/icon-192.png" alt="" width="52" height="52">
    <div class="app-hello">
      <b>Сказочный Край</b>
      <span><?= View::e($me) ?></span>
    </div>
    <div class="app-head-actions">
      <button type="button" class="app-map js-water-open" aria-label="Уровень воды в Шебше" title="Уровень воды в Шебше"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2c3.5 5 7 8.5 7 13a7 7 0 0 1-14 0c0-4.5 3.5-8 7-13Z"/><path d="M9.5 17.5a2.5 2.5 0 0 0 2.5 2.5"/></svg></button>
      <button type="button" class="app-map js-map-open" aria-label="Карта поселения" title="Карта поселения"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></button>
    </div>
  </div>

  <?php $tasks = $tasks ?? []; ?>
  <?php if ($tasks): /* «Мои дела» — только когда есть что делать; нет дел — блока нет. */ ?>
    <a class="app-tasks" href="/poselenie/dela">
      <b>Ждут вас: <?= count($tasks) ?> <?= View::e(plural_ru(count($tasks), 'дело', 'дела', 'дел')) ?></b>
      <span><?= View::e(implode(' · ', array_map(static fn(array $t): string => (string) $t['title'], array_slice($tasks, 0, 2)))) ?></span>
    </a>
  <?php endif; ?>

  <?php
    // Блок дневников — единственная ссылка на раздел с главной (плитки у него
    // нет), поэтому он подчиняется тому же выключателю разделов: иначе при
    // выключенных дневниках он вёл бы на редирект обратно сюда.
  ?>
  <?php if (Sections::isEnabled('dnevniki')): ?>
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
  <?php endif; ?>

  <?php
    // Порядок плиток слева направо, сверху вниз — это раскладка по умолчанию:
    // житель может переставить их длинным нажатием, и тогда его порядок лежит в
    // localStorage и перекрывает этот. Плитки «Дневники поместий» здесь нет
    // намеренно — на дневники уже ведёт широкий блок над сеткой.
  ?>
  <div class="app-grid" id="appGrid">
    <?php if (Sections::isEnabled('sosedi')): ?><a class="app-tile" href="/poselenie/sosedi"><b>Наши<br>соседи</b><span>справочник поселения</span></a><?php endif; ?>
    <a class="app-tile" href="/poselenie/moye-pomestie"><b>Наше<br>поместье</b><span>личный кабинет семьи</span></a>
    <?php if (Sections::isEnabled('knigi')): ?><a class="app-tile" href="/poselenie/knigi"><b>Книги</b><span>библиотека поселения</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('instrumenty')): ?><a class="app-tile" href="/poselenie/instrumenty"><b>Инструменты</b><span>арсенал поселения</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('obshchiy-dom')): ?><a class="app-tile" href="/poselenie/obshchiy-dom"><b>Общий дом</b><span>бронирование и отчёт</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('yarmarka')): ?><a class="app-tile" href="/poselenie/yarmarka"><b>Ярмарка</b><span>рынок поселения</span></a><?php endif; ?>
    <?php if (Sections::isEnabled('zakupki')): ?><?php $bc = (int) ($dash['counts']['purchases'] ?? 0); ?><a class="app-tile" href="/poselenie/zakupki"><b>Закупки</b><span><?= $bc > 0 ? $bc . ' ' . View::e(plural_ru($bc, 'сбор идёт', 'сбора идёт', 'сборов идёт')) : 'оптом вскладчину' ?></span></a><?php endif; ?>
    <?php if (Sections::isEnabled('poezdki')): ?><a class="app-tile" href="/poselenie/poezdki"><b>Поездки</b><span>попутчики и доставка</span></a><?php endif; ?>
  </div>

  <div class="app-offline-banner" id="appOffline">
    <span class="app-offline-dot"></span>
    <span>Сети нет. Показываем сохранённое на <?= View::e($savedAt) ?>, изменения уйдут при связи.</span>
  </div>
</div>

<?php require __DIR__ . '/../partials/map.php'; ?>
<?php require __DIR__ . '/../partials/water.php'; ?>

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
  // Версия в ключе — способ разом сбросить порядок у всех: сохранённая
  // раскладка лежит на устройстве и перекрывает разметку, так что новый
  // порядок по умолчанию сам по себе тем, кто переставлял плитки, не
  // достался бы. Бумп ключа = все снова видят умолчание. Прошлую запись
  // подчищаем, чтобы в хранилище не копился мусор от старых версий.
  var KEY = 'poselenie_tile_order_v2';
  try { localStorage.removeItem('poselenie_tile_order_v1'); } catch (e) {}
  var hrefOf = function (t) { return t.getAttribute('data-href') || t.getAttribute('href') || ''; };
  var keyOf = function (t) { try { return new URL(hrefOf(t), location.origin).pathname; } catch (e) { return hrefOf(t); } };

  // На сенсорных устройствах прячем настоящий href в data-href. Клиенты вроде
  // MAX и Telegram показывают при удержании ссылки свою панель («открыть»,
  // «копировать», «поделиться») нативно, до страницы: ни contextmenu, ни
  // -webkit-touch-callout её не отменяют, и перетащить плитку невозможно. Без
  // href предлагать нечего, а переход делаем сами по клику. На десктопе ссылки
  // остаются настоящими — там удержание ничему не мешает.
  var touch = false;
  try { touch = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); } catch (e) {}
  if (touch) {
    Array.prototype.forEach.call(grid.querySelectorAll('.app-tile[href]'), function (t) {
      t.setAttribute('data-href', t.getAttribute('href'));
      t.removeAttribute('href');
      t.setAttribute('role', 'link');
      t.setAttribute('tabindex', '0');
    });
    grid.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var t = e.target.closest && e.target.closest('.app-tile');
      if (t && hrefOf(t)) { e.preventDefault(); window.location.assign(hrefOf(t)); }
    });
  }

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
  // Если это было перетаскивание, гасим переход по ссылке; обычный тап по
  // плитке без href (сенсорный режим) уводит по сохранённому адресу.
  grid.addEventListener('click', function (e) {
    if (suppress) { e.preventDefault(); e.stopPropagation(); return; }
    var t = e.target.closest && e.target.closest('.app-tile');
    if (t && !t.hasAttribute('href') && hrefOf(t)) { e.preventDefault(); window.location.assign(hrefOf(t)); }
  }, true);
})();
</script>
