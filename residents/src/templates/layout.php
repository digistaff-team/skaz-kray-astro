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
    <p><button type="button" class="res-maplink js-map-open">Поселение родовых поместий «Сказочный Край»</button></p>
</footer>

<?php require __DIR__ . '/partials/map.php'; ?>
</body>
</html>
