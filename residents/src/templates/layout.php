<?php use SkazResidents\View; use SkazResidents\Auth; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="stylesheet" href="/poselenie/assets/residents.css?v=<?= asset_ver('assets/residents.css') ?>">
    <link rel="manifest" href="/poselenie/manifest.webmanifest">
    <meta name="theme-color" content="#008757">
    <link rel="apple-touch-icon" href="/poselenie/assets/icons/icon-192.png">
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('/poselenie/sw.js').catch(function () {});
        });
      }
    </script>
</head>
<body>
<header class="res-header">
    <a class="res-logo" href="/">Сказочный Край</a>
    <nav class="res-nav">
        <?php if (Auth::id() !== null): ?>
            <a href="/poselenie/dnevniki">Дневники поместий</a>
        <?php else: ?>
            <a href="/dnevniki-pomestiy/">Дневники поместий</a>
        <?php endif; ?>
        <a href="/yarmarka/">Ярмарка</a>
        <a href="/poselenie/instrumenty">Инструменты</a>
        <a href="/poselenie/knigi">Книги</a>
        <a href="/poselenie/poezdki">Поездки</a>
        <?php if (Auth::id() !== null): ?>
            <a href="/poselenie/app">Приложение</a>
            <a href="/poselenie/byudzhet">Бюджет</a>
            <a href="/poselenie/">Кабинет</a>
            <?php if (Auth::isEditor()): ?><a href="/poselenie/moderation">Модерация</a><?php endif; ?>
            <a href="/poselenie/vyhod">Выход</a>
        <?php else: ?>
            <a href="/poselenie/vhod">Вход для жителей</a>
        <?php endif; ?>
    </nav>
</header>
<main class="res-main">
    <?php require __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <p>Поселение родовых поместий «Сказочный Край»</p>
</footer>
</body>
</html>
