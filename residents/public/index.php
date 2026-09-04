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
use SkazResidents\Controller\ToolController;
use SkazResidents\Controller\ToolLoanController;
use SkazResidents\Controller\BookController;
use SkazResidents\Controller\BookLoanController;
use SkazResidents\Controller\TripController;
use SkazResidents\Controller\TripBookingController;
use SkazResidents\Controller\TgAuthController;
use SkazResidents\Controller\Council\AuthController as CouncilAuthController;
use SkazResidents\Controller\Council\PagesController as CouncilPagesController;
use SkazResidents\Controller\Council\TaskController as CouncilTaskController;
use SkazResidents\Controller\Council\AdminController as CouncilAdminController;
use SkazResidents\Controller\Council\LedgerController as CouncilLedgerController;
use SkazResidents\Controller\BudgetController;
use SkazResidents\Controller\AppController;
use SkazResidents\Controller\PwaController;

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

// Авто-логин жителя через Telegram Mini App (@SkazKray_bot) с гейтом подписки.
$tg = new TgAuthController();
$router->get('/poselenie/tg', [$tg, 'entry']);
$router->post('/poselenie/tg/login', [$tg, 'login']);
$router->get('/poselenie/tg/gate', [$tg, 'gate']);

$cabinet = new CabinetController();
$router->get('/poselenie', [$cabinet, 'index']);
$router->get('/poselenie/', [$cabinet, 'index']);

$diary = new DiaryController();
$router->get('/poselenie/dnevnik/novaya', [$diary, 'showCreate']);
$router->post('/poselenie/dnevnik/novaya', [$diary, 'create']);
$router->get('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'showEdit']);
$router->post('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'update']);
$router->post('/poselenie/dnevnik/{id}/udalit', [$diary, 'delete']);
$router->post('/poselenie/dnevnik/{id}/foto/{img}/udalit', [$diary, 'deletePhoto']);
// Внутренняя лента (все опубликованные записи, только для вошедших жителей) —
// отдельно от внешней публичной ленты /dnevniki-pomestiy (см. ниже, PublicController).
$router->get('/poselenie/dnevniki', [$diary, 'feed']);
$router->get('/poselenie/dnevniki/{id}', [$diary, 'feedShow']);

$product = new ProductController();
$router->get('/poselenie/yarmarka/novyy', [$product, 'showCreate']);
$router->post('/poselenie/yarmarka/novyy', [$product, 'create']);
$router->get('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'showEdit']);
$router->post('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'update']);
$router->post('/poselenie/yarmarka/{id}/udalit', [$product, 'delete']);

$mod = new ModerationController();
$router->get('/poselenie/moderation', [$mod, 'index']);
$router->post('/poselenie/moderation/family/approve', [$mod, 'approveFamily']);
$router->post('/poselenie/moderation/family/reject', [$mod, 'rejectFamily']);
$router->post('/poselenie/moderation/family/reset-password', [$mod, 'resetPassword']);
$router->post('/poselenie/moderation/entry/approve', [$mod, 'approveEntry']);
$router->post('/poselenie/moderation/entry/reject', [$mod, 'rejectEntry']);
$router->post('/poselenie/moderation/product/approve', [$mod, 'approveProduct']);
$router->post('/poselenie/moderation/product/reject', [$mod, 'rejectProduct']);

// ==== Шеринг инструментов (раздел жителей) ====
$tool = new ToolController();
$loan = new ToolLoanController();
// Специфичные пути — ДО generic '/{id}', иначе {id} их перехватит.
$router->get('/poselenie/instrumenty', [$tool, 'catalog']);
$router->get('/poselenie/instrumenty/novyy', [$tool, 'showCreate']);
$router->post('/poselenie/instrumenty/novyy', [$tool, 'create']);
$router->get('/poselenie/instrumenty/moi', [$tool, 'mine']);
$router->get('/poselenie/instrumenty/{id}/redaktirovat', [$tool, 'showEdit']);
$router->post('/poselenie/instrumenty/{id}/redaktirovat', [$tool, 'update']);
$router->post('/poselenie/instrumenty/{id}/udalit', [$tool, 'delete']);
$router->post('/poselenie/instrumenty/{id}/skryt', [$tool, 'toggleHidden']);
$router->post('/poselenie/instrumenty/{id}/remont', [$tool, 'toggleMaintenance']);
$router->post('/poselenie/instrumenty/{id}/zapros', [$loan, 'request']);
$router->get('/poselenie/instrumenty/{id}', [$tool, 'show']);
// Действия с займами
$router->post('/poselenie/zaymy/{id}/vydat', [$loan, 'give']);
$router->post('/poselenie/zaymy/{id}/otklonit', [$loan, 'decline']);
$router->post('/poselenie/zaymy/{id}/vozvrat', [$loan, 'returnLoan']);
$router->post('/poselenie/zaymy/{id}/otmenit', [$loan, 'cancel']);

// ==== Обмен книгами (раздел жителей) ====
$book = new BookController();
$bloan = new BookLoanController();
$router->get('/poselenie/knigi', [$book, 'catalog']);
$router->get('/poselenie/knigi/novaya', [$book, 'showCreate']);
$router->post('/poselenie/knigi/novaya', [$book, 'create']);
$router->get('/poselenie/knigi/moi', [$book, 'mine']);
$router->get('/poselenie/knigi/{id}/redaktirovat', [$book, 'showEdit']);
$router->post('/poselenie/knigi/{id}/redaktirovat', [$book, 'update']);
$router->post('/poselenie/knigi/{id}/udalit', [$book, 'delete']);
$router->post('/poselenie/knigi/{id}/skryt', [$book, 'toggleHidden']);
$router->post('/poselenie/knigi/{id}/nedostupna', [$book, 'toggleMaintenance']);
$router->post('/poselenie/knigi/{id}/bron', [$bloan, 'request']);
$router->get('/poselenie/knigi/{id}', [$book, 'show']);
$router->post('/poselenie/knigi-bron/{id}/vydat', [$bloan, 'give']);
$router->post('/poselenie/knigi-bron/{id}/otklonit', [$bloan, 'decline']);
$router->post('/poselenie/knigi-bron/{id}/vozvrat', [$bloan, 'returnLoan']);
$router->post('/poselenie/knigi-bron/{id}/otmenit', [$bloan, 'cancel']);

// ==== Совместные поездки (раздел жителей) ====
$trip = new TripController();
$tbook = new TripBookingController();
$router->get('/poselenie/poezdki', [$trip, 'board']);
$router->get('/poselenie/poezdki/novaya', [$trip, 'showCreate']);
$router->post('/poselenie/poezdki/novaya', [$trip, 'create']);
$router->get('/poselenie/poezdki/moi', [$trip, 'mine']);
$router->post('/poselenie/poezdki/{id}/bron', [$tbook, 'book']);
$router->post('/poselenie/poezdki/{id}/zavershit', [$trip, 'markDone']);
$router->post('/poselenie/poezdki/{id}/otmenit', [$trip, 'cancelTrip']);
$router->post('/poselenie/poezdki/{id}/udalit', [$trip, 'delete']);
$router->get('/poselenie/poezdki/{id}', [$trip, 'show']);
$router->post('/poselenie/bron/{id}/podtverdit', [$tbook, 'confirm']);
$router->post('/poselenie/bron/{id}/otklonit', [$tbook, 'decline']);
$router->post('/poselenie/bron/{id}/otmenit', [$tbook, 'cancel']);

// Бюджет Общего дома — read-only отчёт для всех авторизованных жителей.
$budget = new BudgetController();
$router->get('/poselenie/byudzhet', [$budget, 'index']);

// Мобильный PWA: лаунчер, офлайн-страница, manifest и service worker.
$app = new AppController();
$router->get('/poselenie/app', [$app, 'home']);
$router->get('/poselenie/offline', [$app, 'offline']);
$pwa = new PwaController();
$router->get('/poselenie/manifest.webmanifest', [$pwa, 'manifest']);
$router->get('/poselenie/sw.js', [$pwa, 'serviceWorker']);

$public = new PublicController();
$router->get('/dnevniki-pomestiy', [$public, 'diaryList']);
$router->get('/dnevniki-pomestiy/{id}', [$public, 'diaryShow']);
$router->get('/yarmarka', [$public, 'marketList']);
$router->get('/yarmarka/{id}', [$public, 'marketShow']);

// ==== Попечительский совет (/sovet/…) — отдельная авторизация ====
$cAuth = new CouncilAuthController();
$router->get('/sovet/vhod', [$cAuth, 'showLogin']);
$router->post('/sovet/login', [$cAuth, 'login']);
$router->get('/sovet/vyhod', [$cAuth, 'logout']);
$router->get('/sovet/vosstanovit', [$cAuth, 'showForgot']);
$router->post('/sovet/vosstanovit', [$cAuth, 'forgot']);
$router->get('/sovet/sbros', [$cAuth, 'showReset']);
$router->post('/sovet/sbros', [$cAuth, 'reset']);
$router->get('/sovet/parol', [$cAuth, 'showPassword']);
$router->post('/sovet/parol', [$cAuth, 'changePassword']);

$cPages = new CouncilPagesController();
$router->get('/sovet', [$cPages, 'home']);
$router->get('/sovet/', [$cPages, 'home']);
$router->get('/sovet/napravleniya', [$cPages, 'directions']);

$cTasks = new CouncilTaskController();
$router->get('/sovet/zadachi', [$cTasks, 'index']);
$router->post('/sovet/zadachi/novaya', [$cTasks, 'create']);
$router->post('/sovet/zadachi/{id}/obnovit', [$cTasks, 'update']);
$router->post('/sovet/zadachi/{id}/vzyat', [$cTasks, 'take']);
$router->post('/sovet/zadachi/{id}/gotovo', [$cTasks, 'done']);
$router->post('/sovet/zadachi/{id}/vernut', [$cTasks, 'reopen']);
$router->post('/sovet/zadachi/{id}/udalit', [$cTasks, 'delete']);
$router->post('/sovet/zadachi/{id}/podzadacha', [$cTasks, 'addSubtask']);
$router->post('/sovet/podzadacha/{id}/pereklyuchit', [$cTasks, 'toggleSubtask']);
$router->post('/sovet/podzadacha/{id}/pereimenovat', [$cTasks, 'renameSubtask']);
$router->post('/sovet/podzadacha/{id}/udalit', [$cTasks, 'deleteSubtask']);

$cAdmin = new CouncilAdminController();
$router->get('/sovet/upravlenie', [$cAdmin, 'index']);
$router->post('/sovet/upravlenie/dobavit', [$cAdmin, 'add']);
$router->post('/sovet/upravlenie/sbros-parolya', [$cAdmin, 'resetPassword']);
$router->post('/sovet/upravlenie/status', [$cAdmin, 'toggleStatus']);

// Бухгалтерия совета — операции бюджета (все члены) + справочник статей (админ).
$cLedger = new CouncilLedgerController();
$router->get('/sovet/buhgalteriya', [$cLedger, 'index']);
$router->post('/sovet/buhgalteriya/operaciya', [$cLedger, 'create']);
$router->post('/sovet/buhgalteriya/operaciya/{id}/obnovit', [$cLedger, 'update']);
$router->post('/sovet/buhgalteriya/operaciya/{id}/udalit', [$cLedger, 'delete']);
$router->get('/sovet/buhgalteriya/statyi', [$cLedger, 'categories']);
$router->post('/sovet/buhgalteriya/statyi/dobavit', [$cLedger, 'addCategory']);
$router->post('/sovet/buhgalteriya/statyi/{id}/pereimenovat', [$cLedger, 'renameCategory']);
$router->post('/sovet/buhgalteriya/statyi/{id}/arhiv', [$cLedger, 'toggleCategory']);

$found = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if (!$found) {
    http_response_code(404);
    // /poselenie/* и /sovet/* — контекст портала (гварды входа сами решают,
    // что показать); всё остальное (/dnevniki-pomestiy, /yarmarka) приходит
    // с внешнего сайта и не должно показывать интерфейс/навигацию портала.
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $isPortal = str_starts_with($path, '/poselenie') || str_starts_with($path, '/sovet');
    View::render('public/notfound', [], 'Страница не найдена', $isPortal ? 'layout' : 'public/layout');
}
