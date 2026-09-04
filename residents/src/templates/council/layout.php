<?php use SkazResidents\{View, CouncilAuth}; ?>
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
    <a class="res-logo" href="/sovet">Попечительский совет<span class="sovet-sub">внутренний портал</span></a>
    <details class="res-menu">
    <summary class="res-menu-btn" aria-label="Меню" title="Меню"><span class="res-menu-ico"></span></summary>
    <nav class="res-nav">
        <?php if (CouncilAuth::id() !== null): ?>
            <a href="/sovet">Главная</a>
            <a href="/sovet/napravleniya">Направления</a>
            <a href="/sovet/zadachi">Текущие задачи</a>
            <a href="/sovet/buhgalteriya">Бухгалтерия</a>
            <?php if (CouncilAuth::isAdmin()): ?><a href="/sovet/upravlenie">Участники</a><?php endif; ?>
            <a href="/sovet/parol">Пароль</a>
            <a href="/sovet/vyhod">Выход</a>
        <?php else: ?>
            <a href="/">На сайт</a>
            <a href="/sovet/vhod">Вход</a>
        <?php endif; ?>
    </nav>
    </details>
</header>
<main class="res-main sovet-main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <?php if (CouncilAuth::id() !== null): ?><a class="footer-logout" href="/sovet/vyhod">Выход</a><?php endif; ?>
</footer>
</body>
</html>
