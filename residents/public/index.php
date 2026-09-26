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
use SkazResidents\Controller\ResidentsController;
use SkazResidents\Controller\ProfileController;
use SkazResidents\Controller\PublicController;
use SkazResidents\Controller\ToolController;
use SkazResidents\Controller\ToolLoanController;
use SkazResidents\Controller\BookController;
use SkazResidents\Controller\BookLoanController;
use SkazResidents\Controller\CommonHouseController;
use SkazResidents\Controller\PurchaseController;
use SkazResidents\Controller\PurchaseOrderController;
use SkazResidents\Controller\TripController;
use SkazResidents\Controller\TripBookingController;
use SkazResidents\Controller\DeliveryController;
use SkazResidents\Controller\TasksController;
use SkazResidents\Controller\TgAuthController;
use SkazResidents\Controller\MaxAuthController;
use SkazResidents\Controller\Council\AuthController as CouncilAuthController;
use SkazResidents\Controller\Council\PagesController as CouncilPagesController;
use SkazResidents\Controller\Council\TaskController as CouncilTaskController;
use SkazResidents\Controller\Council\AdminController as CouncilAdminController;
use SkazResidents\Controller\Council\LedgerController as CouncilLedgerController;
use SkazResidents\Controller\Council\MeetingController as CouncilMeetingController;
use SkazResidents\Controller\Council\AgendaController as CouncilAgendaController;
use SkazResidents\Controller\Council\TgAuthController as CouncilTgAuthController;
use SkazResidents\Controller\Council\BotWebhookController as CouncilBotWebhookController;
use SkazResidents\Controller\BudgetController;
use SkazResidents\Controller\AppController;
use SkazResidents\Controller\PwaController;
use SkazResidents\Controller\WaterLevelController;

$router = new Router();

$auth = new AuthController();
$router->get('/poselenie/register', [$auth, 'showRegister']);
$router->post('/poselenie/register', [$auth, 'register']);
$router->get('/poselenie/vhod', [$auth, 'showLogin']);
$router->post('/poselenie/login', [$auth, 'login']);
$router->get('/poselenie/vyhod', [$auth, 'logout']);
$router->post('/poselenie/vyhod', [$auth, 'logout']);
$router->get('/poselenie/vosstanovit', [$auth, 'showForgot']);
$router->post('/poselenie/vosstanovit', [$auth, 'forgot']);
$router->get('/poselenie/sbros', [$auth, 'showReset']);
$router->post('/poselenie/sbros', [$auth, 'reset']);

// Авто-логин жителя через Telegram Mini App (@SkazKray_bot) с гейтом подписки.
$tg = new TgAuthController();
$router->get('/poselenie/tg', [$tg, 'entry']);
$router->post('/poselenie/tg/login', [$tg, 'login']);
$router->get('/poselenie/tg/gate', [$tg, 'gate']);

// То же, но через мини-приложение MAX. URL мини-приложения в MAX —
// https://skaz-kray.ru/max (nginx: location ^~ /max → этот index.php). Это
// единственное мини-приложение бота в MAX — оно открывает раздел жителей.
// Гейт — членство в группе жителей MAX (config max.group_chat_id).
$maxAuth = new MaxAuthController();
$router->get('/max', [$maxAuth, 'entry']);
$router->get('/max/', [$maxAuth, 'entry']);
$router->post('/max/login', [$maxAuth, 'login']);
$router->get('/max/gate', [$maxAuth, 'gate']);

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
$router->get('/poselenie/yarmarka', [$product, 'index']);        // «Товары и услуги соседей» — лента рынка
$router->get('/poselenie/yarmarka/moya', [$product, 'mine']);    // «Моя витрина»
$router->get('/poselenie/yarmarka/novyy', [$product, 'showCreate']);
$router->post('/poselenie/yarmarka/novyy', [$product, 'create']);
$router->get('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'showEdit']);
$router->post('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'update']);
$router->post('/poselenie/yarmarka/{id}/udalit', [$product, 'delete']);
$router->post('/poselenie/yarmarka/{id}/foto/{img}/udalit', [$product, 'deletePhoto']);
$router->get('/poselenie/yarmarka/{id}', [$product, 'show']);   // карточка товара (после moya/novyy — те объявлены выше)

// Справочник «Соседи» — карточки поместий и жителей (только для вошедших, ПДн).
$residents = new ResidentsController();
$router->get('/poselenie/sosedi', [$residents, 'index']);
$router->get('/poselenie/sosedi/polyana', [$residents, 'glade']);  // фрагмент: поместья поляны (ленивый режим MAX)

// «Моё поместье» — личный кабинет семьи (правит только своё поместье).
$profile = new ProfileController();
$router->get('/poselenie/moye-pomestie', [$profile, 'index']);
$router->get('/poselenie/moye-pomestie/vybor', [$profile, 'showClaim']);
$router->get('/poselenie/moye-pomestie/vybor/{id}', [$profile, 'showClaimConfirm']);
$router->post('/poselenie/moye-pomestie/vybor/{id}', [$profile, 'claim']);
$router->post('/poselenie/moye-pomestie/sovladelec/udalit', [$profile, 'removeOwner']);
$router->post('/poselenie/moye-pomestie/zayavka/otozvat', [$profile, 'cancelJoin']);
$router->post('/poselenie/moye-pomestie/zayavka/prinyat', [$profile, 'approveJoin']);
$router->post('/poselenie/moye-pomestie/zayavka/otklonit', [$profile, 'rejectJoin']);
$router->get('/poselenie/moye-pomestie/nazvanie', [$profile, 'showEditEstate']);
$router->post('/poselenie/moye-pomestie/nazvanie', [$profile, 'updateEstate']);
$router->get('/poselenie/moye-pomestie/zhitel/novyy', [$profile, 'showAddMember']);
$router->post('/poselenie/moye-pomestie/zhitel/novyy', [$profile, 'addMember']);
$router->get('/poselenie/moye-pomestie/zhitel/{id}/redaktirovat', [$profile, 'showEditMember']);
$router->post('/poselenie/moye-pomestie/zhitel/{id}/redaktirovat', [$profile, 'updateMember']);
$router->post('/poselenie/moye-pomestie/zhitel/{id}/foto/{img}/udalit', [$profile, 'deleteMemberPhoto']);
$router->post('/poselenie/moye-pomestie/zhitel/{id}/udalit', [$profile, 'deleteMember']);
$router->get('/poselenie/moye-pomestie/avto/novyy', [$profile, 'showAddCar']);
$router->post('/poselenie/moye-pomestie/avto/novyy', [$profile, 'addCar']);
$router->get('/poselenie/moye-pomestie/avto/{id}/redaktirovat', [$profile, 'showEditCar']);
$router->post('/poselenie/moye-pomestie/avto/{id}/redaktirovat', [$profile, 'updateCar']);
$router->post('/poselenie/moye-pomestie/avto/{id}/foto/{img}/udalit', [$profile, 'deleteCarPhoto']);
$router->post('/poselenie/moye-pomestie/avto/{id}/udalit', [$profile, 'deleteCar']);
$router->get('/poselenie/moye-pomestie/pitomec/novyy', [$profile, 'showAddPet']);
$router->post('/poselenie/moye-pomestie/pitomec/novyy', [$profile, 'addPet']);
$router->get('/poselenie/moye-pomestie/pitomec/{id}/redaktirovat', [$profile, 'showEditPet']);
$router->post('/poselenie/moye-pomestie/pitomec/{id}/redaktirovat', [$profile, 'updatePet']);
$router->post('/poselenie/moye-pomestie/pitomec/{id}/foto/{img}/udalit', [$profile, 'deletePetPhoto']);
$router->post('/poselenie/moye-pomestie/pitomec/{id}/udalit', [$profile, 'deletePet']);

$mod = new ModerationController();
$router->get('/poselenie/moderation', [$mod, 'index']);
$router->get('/poselenie/moderation/razdely', [$mod, 'sections']);
$router->post('/poselenie/moderation/razdely/pereklyuchit', [$mod, 'toggleSection']);
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
// ==== Доставка (раздел «Поездки») ====
$deliv = new DeliveryController();
$router->get('/poselenie/dostavka', [$deliv, 'board']);
$router->get('/poselenie/dostavka/novaya', [$deliv, 'showCreate']);
$router->post('/poselenie/dostavka/novaya', [$deliv, 'create']);
$router->get('/poselenie/dostavka/moi', [$deliv, 'mine']);
$router->post('/poselenie/dostavka/{id}/vzyat', [$deliv, 'take']);
$router->post('/poselenie/dostavka/{id}/ne-smogu', [$deliv, 'cantDo']);
$router->post('/poselenie/dostavka/{id}/snyat-ispolnitelya', [$deliv, 'unassign']);
$router->post('/poselenie/dostavka/{id}/privez', [$deliv, 'deliver']);
$router->post('/poselenie/dostavka/{id}/chek', [$deliv, 'addReceipt']);
$router->post('/poselenie/dostavka/{id}/rasschitalis', [$deliv, 'settle']);
$router->post('/poselenie/dostavka/{id}/na-dosku', [$deliv, 'toBoard']);
$router->post('/poselenie/dostavka/{id}/otmenit', [$deliv, 'cancel']);
$router->get('/poselenie/dostavka/{id}', [$deliv, 'show']);   // последним среди GET: не перехватить novaya/moi

// ==== Совместные закупки (раздел жителей) ====
$buy = new PurchaseController();
$border = new PurchaseOrderController();
$router->get('/poselenie/zakupki', [$buy, 'board']);
$router->get('/poselenie/zakupki/novaya', [$buy, 'showCreate']);
$router->post('/poselenie/zakupki/novaya', [$buy, 'create']);
$router->get('/poselenie/zakupki/moi', [$buy, 'mine']);
$router->get('/poselenie/zakupki/{id}/redaktirovat', [$buy, 'showEdit']);
$router->post('/poselenie/zakupki/{id}/redaktirovat', [$buy, 'update']);
$router->post('/poselenie/zakupki/{id}/stadiya', [$buy, 'setStatus']);
$router->post('/poselenie/zakupki/{id}/udalit', [$buy, 'delete']);
$router->post('/poselenie/zakupki/{id}/uchastvovat', [$border, 'place']);
$router->post('/poselenie/zakupki/{id}/otkazatsya', [$border, 'withdraw']);
$router->post('/poselenie/zakupki/{id}/oplata/{order}', [$border, 'togglePaid']);
$router->get('/poselenie/zakupki/{id}', [$buy, 'show']);

// Общий дом: хаб с бронированием помещений и отчётом о расходах.
$house = new CommonHouseController();
$router->get('/poselenie/obshchiy-dom', [$house, 'index']);

// Бюджет Общего дома — read-only отчёт для всех авторизованных жителей.
$budget = new BudgetController();
$router->get('/poselenie/byudzhet', [$budget, 'index']);

// Мобильный PWA: лаунчер, офлайн-страница, manifest и service worker.
$app = new AppController();
$router->get('/poselenie/app', [$app, 'home']);
$router->get('/poselenie/dela', [new TasksController(), 'index']);   // «Мои дела»
$router->get('/poselenie/offline', [$app, 'offline']);
// Панель «Уровень воды в Шебше» — фрагмент для модалки на главной приложения.
$waterLevel = new WaterLevelController();
$router->get('/poselenie/uroven-vody', [$waterLevel, 'panel']);

$pwa = new PwaController();
$router->get('/poselenie/manifest.webmanifest', [$pwa, 'manifest']);
$router->get('/poselenie/manifest-sovet.webmanifest', [$pwa, 'manifestSovet']);
$router->get('/poselenie/sw.js', [$pwa, 'serviceWorker']);

$public = new PublicController();
$router->get('/dnevniki-pomestiy', [$public, 'diaryList']);
$router->get('/dnevniki-pomestiy/{id}', [$public, 'diaryShow']);
$router->get('/yarmarka', [$public, 'marketList']);
$router->get('/yarmarka/{id}', [$public, 'marketShow']);

// ==== Попечительский совет (/sovet/…) — отдельная авторизация ====
// Вход членов совета через Telegram Mini App + привязка по фамилии (публичный).
$cTg = new CouncilTgAuthController();
$router->get('/sovet/tg', [$cTg, 'entry']);
$router->post('/sovet/tg/login', [$cTg, 'login']);
$router->get('/sovet/tg/familiya', [$cTg, 'showClaim']);
$router->post('/sovet/tg/claim', [$cTg, 'claim']);
// Webhook бота — нажатия кнопок под уведомлением о задаче (проверка секрет-заголовком).
$router->post('/sovet/tg/webhook', [new CouncilBotWebhookController(), 'handle']);

$cAuth = new CouncilAuthController();
$router->get('/sovet/vhod', [$cAuth, 'showLogin']);
$router->post('/sovet/login', [$cAuth, 'login']);
$router->get('/sovet/vyhod', [$cAuth, 'logout']);
$router->post('/sovet/vyhod', [$cAuth, 'logout']);
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

// Редактирование карточки ближайшего собрания — дежурный председатель или админ.
$cMeeting = new CouncilMeetingController();
$router->get('/sovet/vstrecha', [$cMeeting, 'showEdit']);
$router->post('/sovet/vstrecha', [$cMeeting, 'save']);
$router->post('/sovet/dezhurstvo/peredat', [$cMeeting, 'handoff']);

// Совместная повестка встречи — пункты добавляют сами члены совета.
$cAgenda = new CouncilAgendaController();
$router->get('/sovet/povestka', [$cAgenda, 'index']);
$router->post('/sovet/povestka/dobavit', [$cAgenda, 'add']);
$router->post('/sovet/povestka/udalit', [$cAgenda, 'delete']);
$router->post('/sovet/povestka/obsuzhdeno', [$cAgenda, 'toggle']);
$router->post('/sovet/povestka/peremestit', [$cAgenda, 'move']);

$cTasks = new CouncilTaskController();
$router->get('/sovet/zadachi', [$cTasks, 'index']);
$router->post('/sovet/zadachi/novaya', [$cTasks, 'create']);
$router->post('/sovet/zadachi/{id}/obnovit', [$cTasks, 'update']);
$router->post('/sovet/zadachi/{id}/vzyat', [$cTasks, 'take']);
$router->post('/sovet/zadachi/{id}/gotovo', [$cTasks, 'done']);
$router->post('/sovet/zadachi/{id}/vernut', [$cTasks, 'reopen']);
$router->post('/sovet/zadachi/{id}/udalit', [$cTasks, 'delete']);
$router->post('/sovet/zadachi/{id}/foto/{img}/udalit', [$cTasks, 'deletePhoto']);
$router->post('/sovet/zadachi/{id}/podzadacha', [$cTasks, 'addSubtask']);
$router->post('/sovet/podzadacha/{id}/pereklyuchit', [$cTasks, 'toggleSubtask']);
$router->post('/sovet/podzadacha/{id}/pereimenovat', [$cTasks, 'renameSubtask']);
$router->post('/sovet/podzadacha/{id}/udalit', [$cTasks, 'deleteSubtask']);

$cAdmin = new CouncilAdminController();
$router->get('/sovet/upravlenie', [$cAdmin, 'index']);
$router->post('/sovet/upravlenie/dobavit', [$cAdmin, 'add']);
$router->post('/sovet/upravlenie/sbros-parolya', [$cAdmin, 'resetPassword']);
$router->post('/sovet/upravlenie/status', [$cAdmin, 'toggleStatus']);
$router->post('/sovet/upravlenie/dezhurnyy', [$cAdmin, 'setDutyChair']);
$router->post('/sovet/upravlenie/otvyazat-telegram', [$cAdmin, 'unbindTelegram']);
$router->post('/sovet/upravlenie/kod-privyazki', [$cAdmin, 'issueClaimCode']);

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
