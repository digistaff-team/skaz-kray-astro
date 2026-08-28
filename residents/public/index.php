<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use SkazResidents\Router;
use SkazResidents\View;
use SkazResidents\Controller\AuthController;

$router = new Router();

$auth = new AuthController();
$router->get('/poselenie/register', [$auth, 'showRegister']);
$router->post('/poselenie/register', [$auth, 'register']);
$router->get('/poselenie/vhod', [$auth, 'showLogin']);
$router->post('/poselenie/login', [$auth, 'login']);
$router->get('/poselenie/vyhod', [$auth, 'logout']);
$router->get('/poselenie/vosstanovit', [$auth, 'showForgot']);
$router->post('/poselenie/vosstanovit', [$auth, 'forgot']);
$router->get('/poselenie/sbros', [$auth, 'showReset']);
$router->post('/poselenie/sbros', [$auth, 'reset']);

$found = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if (!$found) {
    http_response_code(404);
    View::render('public/notfound', [], 'Страница не найдена');
}
