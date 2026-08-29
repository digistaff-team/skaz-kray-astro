<?php use SkazResidents\View; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="icon" type="image/jpeg" href="/images/logo.jpg">
    <!-- Точная копия CSS внешнего сайта (site-mirror.css); residents.css
         (стили портала) здесь НЕ подключается — вид 1:1 с рубриками сайта. -->
    <link rel="stylesheet" href="/poselenie/assets/site-mirror.css">
</head>
<body>
<a href="#main" class="skip">Перейти к содержанию</a>
<?php require __DIR__ . '/site_header.php'; ?>
<main id="main">
    <?= $content ?>
</main>
<?php require __DIR__ . '/site_footer.php'; ?>
</body>
</html>
