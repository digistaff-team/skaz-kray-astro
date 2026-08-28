<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use SkazResidents\Router;
use SkazResidents\View;

$router = new Router();

// Проверочный маршрут (удалить после Task 13)
$router->get('/poselenie/ping', function () {
    View::render('auth/ping', [], 'ping');
});

$found = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if (!$found) {
    http_response_code(404);
    View::render('public/notfound', [], 'Страница не найдена');
}
