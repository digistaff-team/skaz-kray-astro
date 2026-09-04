<?php use SkazResidents\{View, CouncilAuth}; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="stylesheet" href="/poselenie/assets/residents.css?v=<?= asset_ver('assets/residents.css') ?>">
</head>
<body>
<header class="res-header">
    <a class="res-logo" href="/sovet">Попечительский совет<span class="sovet-sub">внутренний портал</span></a>
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
</header>
<main class="res-main sovet-main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <p>Попечительский совет Общего дома · Поселение «Сказочный Край»</p>
</footer>
</body>
</html>
