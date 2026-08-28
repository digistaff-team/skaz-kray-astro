<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use SkazResidents\Router;
use SkazResidents\View;
use SkazResidents\Controller\AuthController;
use SkazResidents\Controller\CabinetController;
use SkazResidents\Controller\DiaryController;
use SkazResidents\Controller\ProductController;
use SkazResidents\Controller\ModerationController;
use SkazResidents\Controller\PublicController;

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

$cabinet = new CabinetController();
$router->get('/poselenie', [$cabinet, 'index']);
$router->get('/poselenie/', [$cabinet, 'index']);

$diary = new DiaryController();
$router->get('/poselenie/dnevnik/novaya', [$diary, 'showCreate']);
$router->post('/poselenie/dnevnik/novaya', [$diary, 'create']);
$router->get('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'showEdit']);
$router->post('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'update']);
$router->get('/poselenie/dnevnik/{id}/udalit', [$diary, 'delete']);

$product = new ProductController();
$router->get('/poselenie/yarmarka/novyy', [$product, 'showCreate']);
$router->post('/poselenie/yarmarka/novyy', [$product, 'create']);
$router->get('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'showEdit']);
$router->post('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'update']);
$router->get('/poselenie/yarmarka/{id}/udalit', [$product, 'delete']);

$mod = new ModerationController();
$router->get('/poselenie/moderation', [$mod, 'index']);
$router->post('/poselenie/moderation/family/approve', [$mod, 'approveFamily']);
$router->post('/poselenie/moderation/family/reject', [$mod, 'rejectFamily']);
$router->post('/poselenie/moderation/family/reset-password', [$mod, 'resetPassword']);
$router->post('/poselenie/moderation/entry/approve', [$mod, 'approveEntry']);
$router->post('/poselenie/moderation/entry/reject', [$mod, 'rejectEntry']);
$router->post('/poselenie/moderation/product/approve', [$mod, 'approveProduct']);
$router->post('/poselenie/moderation/product/reject', [$mod, 'rejectProduct']);

$public = new PublicController();
$router->get('/dnevniki-pomestiy', [$public, 'diaryList']);
$router->get('/dnevniki-pomestiy/{id}', [$public, 'diaryShow']);
$router->get('/yarmarka', [$public, 'marketList']);
$router->get('/yarmarka/{id}', [$public, 'marketShow']);

$found = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if (!$found) {
    http_response_code(404);
    View::render('public/notfound', [], 'Страница не найдена');
}
