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
<?php require __DIR__ . '/site_header.php'; ?>
<main id="main" class="site-mirror">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<?php require __DIR__ . '/site_footer.php'; ?>
</body>
</html>
