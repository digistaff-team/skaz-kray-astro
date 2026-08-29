<?php use SkazResidents\View; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="stylesheet" href="/poselenie/assets/residents.css">
</head>
<body>
<header class="res-header">
    <a class="res-logo" href="/">Сказочный Край</a>
</header>
<main class="res-main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <p>Поселение родовых поместий «Сказочный Край»</p>
</footer>
</body>
</html>
