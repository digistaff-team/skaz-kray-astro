<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload, TelegramMedia};
use SkazResidents\Repository\{ProductRepository, ImageRepository, FamilyRepository, HouseholdProfileRepository};
use SkazResidents\Service\{CatalogAnnounce, AfterResponse};

final class ProductController
{
    use RequiresHousehold;

    /** Сколько секунд после размещения товар с тем же названием считается повтором формы. */
    private const DUPLICATE_WINDOW = 120;

    /** Единицы измерения цены: товары (шт., кг.) и услуги (час, день, услуга). */
    public const UNITS = ['шт.', 'час', 'кг.', 'г.', 'л.', 'м.', 'м²', 'м³', 'день', 'комплект', 'упаковка', 'банка', 'пучок', 'услуга'];

    public function __construct(
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository(),
        private HouseholdProfileRepository $households = new HouseholdProfileRepository(),
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /** Лента «Товары и услуги соседей» — внутрипоселенческий рынок (все опубликованные товары). */
    public function index(): void
    {
        $this->requireHousehold('yarmarka');
        $products = $this->products->listAvailable(60, 0);
        foreach ($products as &$p) {
            $imgs = $this->images->listFor('product', (int) $p['id']);
            $p['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($p);
        View::render('product/marketplace', ['products' => $products, 'me' => Auth::id()], 'Товары и услуги соседей');
    }

    /** «Моя витрина» — свои товары/услуги (любой статус), управление. */
    public function mine(): void
    {
        $this->requireHousehold('yarmarka');
        $products = $this->products->listByFamily(Auth::id());
        foreach ($products as &$p) {
            $imgs = $this->images->listFor('product', (int) $p['id']);
            $p['photo'] = $imgs[0]['path'] ?? null;
        }
        unset($p);
        View::render('product/mine', ['products' => $products], 'Моя витрина');
    }

    /** Полная карточка товара внутри портала: описание, все фото, контакты. */
    public function show(array $params): void
    {
        $this->requireHousehold('yarmarka');
        $product = $this->products->findById((int) $params['id']);
        // Соседям видно опубликованное; свой товар владелец открывает в любом статусе.
        if ($product === null
            || ((string) $product['status'] !== 'published' && (int) $product['family_id'] !== Auth::id())) {
            http_response_code(404);
            View::render('public/notfound', [], 'Товар не найден');
            return;
        }
        $product['family_name'] = (string) ($this->families->findById((int) $product['family_id'])['name'] ?? '');
        View::render('product/show', [
            'product' => $product,
            'images'  => $this->images->listFor('product', (int) $product['id']),
            'isOwner' => (int) $product['family_id'] === Auth::id(),
        ], $product['title']);
    }

    public function showCreate(): void
    {
        $this->requireHousehold('yarmarka');
        View::render('product/form', [
            'product' => ['contact' => $this->defaultContact()],
            'images' => [],
            'units' => self::UNITS,
            'errors' => [],
        ], 'Новый товар/услуга');
    }

    /**
     * Контакты авторизованного жителя для подстановки в форму нового товара: телефон
     * из карточки поместья (первый заполненный среди жителей — они отсортированы, первым идёт
     * хозяин) и @username из Telegram-аккаунта. Поле остаётся редактируемым: это только заготовка.
     */
    private function defaultContact(): string
    {
        $familyId = Auth::id();
        if ($familyId === null) { return ''; }
        $parts = [];
        $household = $this->households->householdByFamily($familyId);
        if ($household !== null) {
            foreach ($this->households->members((int) $household['id']) as $m) {
                $phone = trim((string) ($m['phone'] ?? ''));
                if ($phone !== '') { $parts[] = $phone; break; }
            }
        }
        $username = trim((string) ($this->families->findById($familyId)['telegram_username'] ?? ''));
        if ($username !== '') { $parts[] = '@' . ltrim($username, '@'); }
        return implode(', ', $parts);
    }

    public function create(): void
    {
        $this->requireHousehold('yarmarka');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('product/form', ['product' => $data, 'images' => [], 'units' => self::UNITS, 'errors' => $errors], 'Новый товар/услуга');
            return;
        }
        // Страховка от повторной отправки: форма с фото уходит долго, и телефон может
        // оборвать соединение до ответа — запрос при этом доходит, и повторное нажатие рожало ещё
        // одну карточку и ещё один анонс в общий чат. Кнопка блокируется и на клиенте (submit-guard).
        $dup = $this->products->findRecentByTitle(Auth::id(), $data['title'], date('Y-m-d H:i:s', time() - self::DUPLICATE_WINDOW));
        if ($dup !== null) {
            // Если предыдущий запрос успел создать товар, но был убит до загрузки фото —
            // докладываем снимки к нему, а не плодим вторую карточку.
            $needPhotos = $this->images->listFor('product', (int) $dup['id']) === [];
            Flash::set('success', 'Этот товар уже размещён — повторная отправка формы дубль не создала.'
                . ($needPhotos ? self::photosNote() : ''));
            header('Location: /poselenie/yarmarka/moya');
            if ($needPhotos) {
                $dupId = (int) $dup['id'];
                AfterResponse::run(fn() => $this->handleUploads($dupId), 'Ярмарка');
            }
            return;
        }
        $id = $this->products->create(Auth::id(), $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'), $data['visibility'], $data['unit']);
        Flash::set('success', ($data['visibility'] === 'public'
            ? 'Товар отправлен на проверку — после неё появится в разделе Ярмарка на сайте.'
            : 'Товар опубликован на внутрипоселенческом рынке (виден соседям).') . self::photosNote());
        header('Location: /poselenie/yarmarka/moya');
        // Всё долгое — после ответа: снимки едут в Telegram по 10–15 секунд.
        // Анонсируем только товар «только соседям»: он публикуется сразу, а товар
        // «на сайте» ждёт проверки и его анонсирует модерация (ModerationController::approveProduct).
        AfterResponse::run(function () use ($id, $data): void {
            if ($data['visibility'] !== 'public') {
                CatalogAnnounce::product($id, $data['title'], $data['price'], $data['unit']);
            }
            $this->handleUploads($id);
        }, 'Ярмарка');
    }

    /** Приписка к сообщению: фото грузятся уже после редиректа, карточка секунду-другую без них. */
    private static function photosNote(): string
    {
        return self::hasPhotos() ? ' Фото появятся через несколько секунд.' : '';
    }

    /** Есть ли в запросе хоть один реально выбранный файл. */
    private static function hasPhotos(): bool
    {
        $errors = $_FILES['photos']['error'] ?? null;
        foreach (is_array($errors) ? $errors : [$errors] as $err) {
            if ($err !== null && $err !== UPLOAD_ERR_NO_FILE) { return true; }
        }
        return false;
    }

    public function showEdit(array $params): void
    {
        $this->requireHousehold('yarmarka');
        $product = $this->ownedOr404((int) $params['id']);
        View::render('product/form', [
            'product' => $product,
            'images' => $this->images->listFor('product', (int) $product['id']),
            'units' => self::UNITS,
            'errors' => [],
        ], 'Редактирование товара');
    }

    public function update(array $params): void
    {
        $this->requireHousehold('yarmarka');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $product['id'];
            View::render('product/form', ['product' => $data, 'images' => $this->images->listFor('product', (int) $product['id']), 'units' => self::UNITS, 'errors' => $errors], 'Редактирование товара');
            return;
        }
        $this->products->update((int) $product['id'], $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'), $data['visibility'], $data['unit']);
        Flash::set('success', ($data['visibility'] === 'public'
            ? 'Изменения отправлены на проверку (раздел Ярмарка на сайте).'
            : 'Товар обновлён на внутрипоселенческом рынке.') . self::photosNote());
        header('Location: /poselenie/yarmarka/moya');
        $productId = (int) $product['id'];
        AfterResponse::run(fn() => $this->handleUploads($productId), 'Ярмарка');
    }

    public function delete(array $params): void
    {
        $this->requireHousehold('yarmarka');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        $this->deleteImageFiles((int) $product['id']);
        $this->images->deleteFor('product', (int) $product['id']);
        $this->products->delete((int) $product['id']);
        Flash::set('success', 'Удалено.');
        header('Location: /poselenie/yarmarka/moya');
    }

    /** Удаление одного уже загруженного фото товара (в режиме редактирования). */
    public function deletePhoto(array $params): void
    {
        $this->requireHousehold('yarmarka');
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        $imgId = (int) ($params['img'] ?? 0);
        foreach ($this->images->listFor('product', (int) $product['id']) as $img) {
            if ((int) $img['id'] !== $imgId) { continue; }
            $path = (string) $img['path'];
            if (!str_starts_with($path, 'tg:')) {
                @unlink(rtrim((string) Config::get('uploads_dir'), '/\\') . '/' . basename($path));
            }
            $this->images->deleteById($imgId);
            Flash::set('info', 'Фото удалено.');
            break;
        }
        header('Location: /poselenie/yarmarka/' . (int) $product['id'] . '/redaktirovat');
    }

    /** Удаляет физические файлы фото товара из uploads_dir (строки БД чистит deleteFor). */
    private function deleteImageFiles(int $ownerId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('product', $ownerId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $unit  = trim($_POST['unit'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Название: 2–200 символов.'; }
        if (!Validator::required($desc)) { $errors['description'] = 'Опишите товар или услугу.'; }
        if (!Validator::length($contact, 3, 200)) { $errors['contact'] = 'Укажите, как с вами связаться.'; }
        if ($price !== '' && self::num($price) === null) { $errors['price'] = 'Цена — число в рублях, например 500 или 500.50.'; }
        $price = $price === '' ? '' : (self::num($price) ?? $price);
        return [[
            'title' => $title, 'description' => $desc,
            'price' => $price === '' ? null : $price,
            // Единица осмысленна только вместе с ценой (в форме она и появляется только при заполненной цене);
            // чужое значение из подменённой формы просто отбрасываем.
            'unit' => ($price !== '' && in_array($unit, self::UNITS, true)) ? $unit : null,
            'contact' => $contact,
            'visibility' => $this->pickVisibility($_POST['visibility'] ?? ''),
        ], $errors];
    }

    /**
     * Цена — только цифры в рублях (копейки через точку или запятую), как в закупках.
     * Старые товары с ценой-текстом остаются как есть, пока их не откроют на редактирование.
     */
    private static function num(string $raw): ?string
    {
        $v = str_replace([' ', ','], ['', '.'], $raw);
        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v) ? $v : null;
    }

    /** residents («только соседи») | public («на сайте»); дефолт — residents. */
    private function pickVisibility(string $v): string
    {
        return $v === 'public' ? 'public' : 'residents';
    }

    private function handleUploads(int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $f = $_FILES['photos'];
        $files = is_array($f['name'])
            ? array_map(fn($i) => [
                'name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ], array_keys($f['name']))
            : [$f];
        $sort = count($this->images->listFor('product', $ownerId));
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            // Фото товара уходят в тот же Telegram-канал, что дневник/новости.
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) { $this->images->add('product', $ownerId, 'tg:' . $fileId, $sort++); continue; }
            // Фолбэк на локальное хранилище, если Telegram недоступен.
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) { $this->images->add('product', $ownerId, $name, $sort++); }
            // Flash здесь бесполезен: загрузка идёт после ответа, сессия уже закрыта.
            elseif ($err !== null) { error_log('Ярмарка: фото не загрузилось — ' . $err); }
        }
    }

    private function ownedOr404(int $id): array
    {
        $p = $this->products->findById($id);
        if (!$p || (int) $p['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Товар не найден');
            exit;
        }
        return $p;
    }
}
