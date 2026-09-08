<?php use SkazResidents\View; use SkazResidents\Auth; use SkazResidents\Sections; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="stylesheet" href="/poselenie/assets/residents.css?v=<?= asset_ver('assets/residents.css') ?>">
    <link rel="manifest" href="/poselenie/manifest.webmanifest">
    <meta name="theme-color" content="#008757">
    <link rel="apple-touch-icon" href="/poselenie/assets/icons/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Сказочный Край">
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('/poselenie/sw.js', { scope: '/' }).catch(function () {});
        });
      }
    </script>
</head>
<body>
<header class="res-header">
    <a class="res-logo" href="/">Сказочный Край</a>
    <details class="res-menu">
    <summary class="res-menu-btn" aria-label="Меню" title="Меню"><span class="res-menu-ico"></span></summary>
    <nav class="res-nav">
        <?php if (Auth::id() !== null): ?>
            <?php if (Sections::isEnabled('dnevniki')): ?><a href="/poselenie/dnevniki">Дневники поместий</a><?php endif; ?>
        <?php else: ?>
            <a href="/dnevniki-pomestiy/">Дневники поместий</a>
        <?php endif; ?>
        <a href="/yarmarka/">Ярмарка</a>
        <?php if (Sections::isEnabled('instrumenty')): ?><a href="/poselenie/instrumenty">Инструменты</a><?php endif; ?>
        <?php if (Sections::isEnabled('knigi')): ?><a href="/poselenie/knigi">Книги</a><?php endif; ?>
        <?php if (Sections::isEnabled('poezdki')): ?><a href="/poselenie/poezdki">Поездки</a><?php endif; ?>
        <?php if (Auth::id() !== null): ?>
            <?php if (Sections::isEnabled('sosedi')): ?><a href="/poselenie/sosedi">Соседи</a><?php endif; ?>
            <a href="/poselenie/moye-pomestie">Наше поместье</a>
            <a href="/poselenie/app">Приложение</a>
            <?php if (Sections::isEnabled('byudzhet')): ?><a href="/poselenie/byudzhet">Бюджет</a><?php endif; ?>
            <?php if (Auth::isEditor()): ?><a href="/poselenie/moderation">Модерация</a><?php endif; ?>
            <?php if (Auth::isAdmin()): ?><a href="/poselenie/moderation/razdely">Разделы</a><?php endif; ?>
            <a href="/poselenie/vyhod">Выход</a>
        <?php else: ?>
            <a href="/poselenie/vhod">Вход для жителей</a>
        <?php endif; ?>
    </nav>
    </details>
</header>
<main class="res-main">
    <?php require __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <p><button type="button" class="res-maplink" id="mapOpen">Поселение родовых поместий «Сказочный Край»</button></p>
</footer>

<!-- Полноэкранная карта поселения -->
<div class="map-overlay" id="mapOverlay" hidden>
    <button type="button" class="map-close" id="mapClose" aria-label="Закрыть карту" title="Закрыть">&times;</button>
    <div class="map-scroll" id="mapScroll">
        <img src="/poselenie/assets/karta-sk.png?v=<?= asset_ver('assets/karta-sk.png') ?>" alt="Карта поселения «Сказочный Край»" class="map-img" id="mapImg">
    </div>
</div>
<script>
(function () {
  var openBtn = document.getElementById('mapOpen');
  var overlay = document.getElementById('mapOverlay');
  var closeBtn = document.getElementById('mapClose');
  var scroll = document.getElementById('mapScroll');
  var img = document.getElementById('mapImg');
  if (!openBtn || !overlay) return;

  var s = 1, tx = 0, ty = 0, MIN = 1, MAX = 6;
  function apply() { img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + s + ')'; }
  function reset() { s = 1; tx = 0; ty = 0; apply(); }
  function clamp(v, a, b) { return Math.min(b, Math.max(a, v)); }
  function crect() { return scroll.getBoundingClientRect(); }

  // Масштаб вокруг точки (px,py в координатах контейнера); центр — начало отсчёта transform.
  function zoomTo(ns, px, py) {
    ns = clamp(ns, MIN, MAX);
    var r = crect(), cx = r.width / 2, cy = r.height / 2;
    var ix = (px - cx - tx) / s, iy = (py - cy - ty) / s;
    tx = px - cx - ix * ns; ty = py - cy - iy * ns;
    s = ns;
    if (s <= 1.001) { s = 1; tx = 0; ty = 0; }
    apply();
  }

  function show() { overlay.hidden = false; document.body.classList.add('map-open'); reset(); }
  function hide() { overlay.hidden = true; document.body.classList.remove('map-open'); reset(); }
  openBtn.addEventListener('click', show);
  closeBtn.addEventListener('click', hide);
  // Клик по краю тёмного фона (мимо контейнера с картой) — закрывает.
  overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) hide(); });

  // --- Сенсор: пинч-зум двумя пальцами, панорамирование одним, двойной тап ---
  var prevDist = 0, lastX = 0, lastY = 0, panning = false, lastTap = 0;
  function dist(t) { return Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY); }
  function midC(t) { var r = crect(); return { x: (t[0].clientX + t[1].clientX) / 2 - r.left, y: (t[0].clientY + t[1].clientY) / 2 - r.top }; }

  scroll.addEventListener('touchstart', function (e) {
    if (e.touches.length === 2) { prevDist = dist(e.touches); }
    else if (e.touches.length === 1) {
      panning = s > 1; lastX = e.touches[0].clientX; lastY = e.touches[0].clientY;
      var now = e.timeStamp || 0;
      if (now - lastTap < 300) {           // двойной тап — приблизить/сбросить
        var r = crect();
        zoomTo(s > 1 ? 1 : 2.5, e.touches[0].clientX - r.left, e.touches[0].clientY - r.top);
        lastTap = 0;
      } else { lastTap = now; }
    }
  }, { passive: false });

  scroll.addEventListener('touchmove', function (e) {
    if (e.touches.length === 2) {
      e.preventDefault();
      var nd = dist(e.touches); if (!prevDist) { prevDist = nd; return; }
      var m = midC(e.touches);
      zoomTo(s * nd / prevDist, m.x, m.y);
      prevDist = nd;
    } else if (e.touches.length === 1 && panning) {
      e.preventDefault();
      tx += e.touches[0].clientX - lastX; ty += e.touches[0].clientY - lastY;
      lastX = e.touches[0].clientX; lastY = e.touches[0].clientY; apply();
    }
  }, { passive: false });

  scroll.addEventListener('touchend', function (e) {
    if (e.touches.length < 2) prevDist = 0;
    if (e.touches.length === 0) panning = false;
  });

  // --- Мышь (десктоп): колесо — зум к курсору, перетаскивание, двойной клик ---
  scroll.addEventListener('wheel', function (e) {
    e.preventDefault();
    var r = crect();
    zoomTo(s * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX - r.left, e.clientY - r.top);
  }, { passive: false });
  var mdown = false, mx = 0, my = 0;
  scroll.addEventListener('mousedown', function (e) { if (s > 1) { mdown = true; mx = e.clientX; my = e.clientY; img.style.cursor = 'grabbing'; e.preventDefault(); } });
  window.addEventListener('mousemove', function (e) { if (mdown) { tx += e.clientX - mx; ty += e.clientY - my; mx = e.clientX; my = e.clientY; apply(); } });
  window.addEventListener('mouseup', function () { mdown = false; img.style.cursor = 'grab'; });
  scroll.addEventListener('dblclick', function (e) { var r = crect(); zoomTo(s > 1 ? 1 : 2.5, e.clientX - r.left, e.clientY - r.top); });
})();
</script>
</body>
</html>
