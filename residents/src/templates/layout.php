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
  function show() { overlay.hidden = false; document.body.classList.add('map-open'); }
  function hide() {
    overlay.hidden = true;
    document.body.classList.remove('map-open');
    img.classList.remove('map-img--zoom');
    scroll.scrollTop = 0; scroll.scrollLeft = 0;
  }
  openBtn.addEventListener('click', show);
  closeBtn.addEventListener('click', hide);
  // Клик по тёмному фону (мимо картинки) — тоже закрывает.
  overlay.addEventListener('click', function (e) { if (e.target === overlay || e.target === scroll) hide(); });
  // Тап по карте — переключение «вписать в экран» ⇄ «в натуральном размере» (со скроллом).
  img.addEventListener('click', function (e) { e.stopPropagation(); img.classList.toggle('map-img--zoom'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) hide(); });
})();
</script>
</body>
</html>
