<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload, TelegramMedia};
use SkazResidents\Repository\{HouseholdProfileRepository, ImageRepository};

/**
 * «Наше поместье» — личный кабинет семьи. Вошедший под аккаунтом поместья
 * (households.family_id = Auth::id()) видит и правит ТОЛЬКО своё поместье:
 * данные жителей (добавить/изменить/удалить), автомобили, название поместья.
 * Правки применяются сразу. Поляна/участок/статус — только просмотр.
 */
final class ProfileController
{
    public function __construct(
        private HouseholdProfileRepository $repo = new HouseholdProfileRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $h = $this->repo->householdByFamily(Auth::id());
        if (!$h) {
            header('Location: /poselenie/moye-pomestie/vybor'); // выбрать своё поместье
            return;
        }
        $members = $this->repo->members((int) $h['id']);
        foreach ($members as &$m) { $m['images'] = $this->images->listFor('resident', (int) $m['id']); }
        unset($m);
        $cars = $this->repo->cars((int) $h['id']);
        foreach ($cars as &$c) { $c['images'] = $this->images->listFor('car', (int) $c['id']); }
        unset($c);
        $pets = $this->repo->pets((int) $h['id']);
        foreach ($pets as &$pet) { $pet['images'] = $this->images->listFor('pet', (int) $pet['id']); }
        unset($pet);
        View::render('profile/index', [
            'household' => $h,
            'members'   => $members,
            'cars'      => $cars,
            'pets'      => $pets,
            'owners'    => $this->repo->owners((int) $h['id']),
            'uid'       => Auth::id(),
        ], 'Наше поместье');
    }

    // ── Выбор своего поместья (привязка аккаунта) ───────────────────────────
    public function showClaim(): void
    {
        Auth::requireLogin();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        // Показываем ВСЕ участки поляны (число фиксировано), группируем в шаблоне.
        // Занятые — зелёным (не открыть), свободные с жителями — серым (можно привязать).
        View::render('profile/claim', ['households' => $this->repo->listForClaimView()], 'Выбор поместья');
    }

    public function showClaimConfirm(array $p): void
    {
        Auth::requireLogin();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        $id = (int) $p['id'];
        $h = $this->repo->householdById($id);
        if (!$h) { $this->notFound('Поместье не найдено'); }
        // Занятое поместье можно не привязать, а присоединиться к нему совладельцем.
        $occupied = !$this->repo->isClaimable($id);
        // ПДн жителей не показываем — подтверждаем вводом фамилии (если жители есть).
        View::render('profile/claim-confirm', [
            'household'  => $h,
            'hasMembers' => $this->repo->members($id) !== [],
            'occupied'   => $occupied,
        ], 'Подтверждение поместья');
    }

    public function claim(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        $id = (int) $p['id'];
        $h = $this->repo->householdById($id);
        if (!$h) {
            Flash::set('error', 'Поместье не найдено.');
            header('Location: /poselenie/moye-pomestie/vybor');
            return;
        }
        // Проверка принадлежности: если в поместье есть жители — подтверждаем фамилией.
        $surname = trim($_POST['surname'] ?? '');
        if ($this->repo->members($id) !== []) {
            if (!$this->repo->surnameMatchesHousehold($id, $surname)) {
                Flash::set('error', 'Такой фамилии нет среди жителей этого поместья. Проверьте написание или обратитесь к редактору.');
                header('Location: /poselenie/moye-pomestie/vybor/' . $id);
                return;
            }
        }
        if ($this->repo->isClaimable($id)) {
            // Свободное поместье — становимся первичным владельцем.
            if (!$this->repo->claim($id, Auth::id())) {
                Flash::set('error', 'Не удалось привязать поместье — попробуйте ещё раз.');
                header('Location: /poselenie/moye-pomestie/vybor');
                return;
            }
            // Запоминаем фамилию, по которой привязались, — для показа «Tg» у нужного жителя.
            if ($surname !== '') { $this->repo->setClaimSurname($id, $surname); }
            Flash::set('success', 'Поместье привязано к вашему аккаунту — теперь можно проверять и править данные.');
        } else {
            // Занятое поместье — присоединяемся к семье как совладелец.
            $this->repo->joinAsOwner($id, Auth::id());
            Flash::set('success', 'Вы добавлены как совладелец поместья — теперь можно вести данные вместе с семьёй.');
        }
        header('Location: /poselenie/moye-pomestie');
    }

    // ── Совместное владение ─────────────────────────────────────────────────
    /** Убрать совладельца (в т.ч. себя). Доступно любому владельцу этого поместья. */
    public function removeOwner(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        $familyId = (int) ($_POST['family_id'] ?? 0);
        $ownerIds = array_map(static fn(array $o): int => (int) $o['family_id'], $this->repo->owners((int) $h['id']));
        if (!in_array($familyId, $ownerIds, true)) {
            Flash::set('error', 'Такого совладельца нет.');
            header('Location: /poselenie/moye-pomestie');
            return;
        }
        if (count($ownerIds) <= 1) {
            Flash::set('error', 'Нельзя убрать единственного владельца поместья.');
            header('Location: /poselenie/moye-pomestie');
            return;
        }
        $self = $familyId === Auth::id();
        $this->repo->removeOwner((int) $h['id'], $familyId);
        Flash::set('success', $self ? 'Вы вышли из совместного владения поместьем.' : 'Совладелец убран.');
        header('Location: ' . ($self ? '/poselenie/app' : '/poselenie/moye-pomestie'));
    }

    // ── Название поместья ───────────────────────────────────────────────────
    public function showEditEstate(): void
    {
        Auth::requireLogin();
        $h = $this->myHouseholdOr404();
        View::render('profile/estate-form', ['household' => $h, 'errors' => []], 'Название поместья');
    }

    public function updateEstate(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        $name = trim($_POST['estate_name'] ?? '');
        if (!Validator::length($name, 0, 160)) {
            View::render('profile/estate-form', ['household' => $h, 'errors' => ['estate_name' => 'Не длиннее 160 символов.']], 'Название поместья');
            return;
        }
        $this->repo->updateHouseholdEstate((int) $h['id'], $name);
        Flash::set('success', 'Название поместья обновлено.');
        header('Location: /poselenie/moye-pomestie');
    }

    // ── Жители ──────────────────────────────────────────────────────────────
    public function showAddMember(): void
    {
        Auth::requireLogin();
        $this->myHouseholdOr404();
        View::render('profile/member-form', ['member' => null, 'images' => [], 'errors' => []], 'Новый житель');
    }

    public function addMember(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validateMember();
        if ($errors) {
            View::render('profile/member-form', ['member' => $data, 'images' => [], 'errors' => $errors], 'Новый житель');
            return;
        }
        $id = $this->repo->addMember((int) $h['id'], $data, $this->today());
        $this->handleUploads('resident', $id);
        Flash::set('success', 'Житель добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditMember(array $p): void
    {
        Auth::requireLogin();
        $m = $this->myMemberOr404((int) $p['id']);
        View::render('profile/member-form', [
            'member' => $m,
            'images' => $this->images->listFor('resident', (int) $m['id']),
            'errors' => [],
        ], 'Изменить данные жителя');
    }

    public function updateMember(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $m = $this->myMemberOr404((int) $p['id']);
        [$data, $errors] = $this->validateMember();
        if ($errors) {
            $data['id'] = $m['id'];
            View::render('profile/member-form', [
                'member' => $data,
                'images' => $this->images->listFor('resident', (int) $m['id']),
                'errors' => $errors,
            ], 'Изменить данные жителя');
            return;
        }
        $this->repo->updateMember((int) $m['id'], $data, $this->today());
        $this->handleUploads('resident', (int) $m['id']);
        Flash::set('success', 'Данные жителя обновлены.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteMember(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $m = $this->myMemberOr404((int) $p['id']);
        $this->deleteAllPhotos('resident', (int) $m['id']);
        $this->repo->deleteMember((int) $m['id']);
        Flash::set('info', 'Житель удалён из поместья.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteMemberPhoto(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $m = $this->myMemberOr404((int) $p['id']);
        $this->deleteOnePhoto('resident', (int) $m['id'], (int) ($p['img'] ?? 0));
        header('Location: /poselenie/moye-pomestie/zhitel/' . (int) $m['id'] . '/redaktirovat');
    }

    // ── Автомобили ──────────────────────────────────────────────────────────
    public function showAddCar(): void
    {
        Auth::requireLogin();
        $this->myHouseholdOr404();
        View::render('profile/car-form', ['car' => null, 'images' => [], 'errors' => []], 'Новый автомобиль');
    }

    public function addCar(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validateCar();
        if ($errors) {
            View::render('profile/car-form', ['car' => $data, 'images' => [], 'errors' => $errors], 'Новый автомобиль');
            return;
        }
        $id = $this->repo->addCar((int) $h['id'], $data['title'], $data['plate'], $data['note']);
        $this->handleUploads('car', $id);
        Flash::set('success', 'Автомобиль добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditCar(array $p): void
    {
        Auth::requireLogin();
        $c = $this->myCarOr404((int) $p['id']);
        View::render('profile/car-form', [
            'car' => $c,
            'images' => $this->images->listFor('car', (int) $c['id']),
            'errors' => [],
        ], 'Изменить автомобиль');
    }

    public function updateCar(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $c = $this->myCarOr404((int) $p['id']);
        [$data, $errors] = $this->validateCar();
        if ($errors) {
            $data['id'] = $c['id'];
            View::render('profile/car-form', [
                'car' => $data,
                'images' => $this->images->listFor('car', (int) $c['id']),
                'errors' => $errors,
            ], 'Изменить автомобиль');
            return;
        }
        $this->repo->updateCar((int) $c['id'], $data['title'], $data['plate'], $data['note']);
        $this->handleUploads('car', (int) $c['id']);
        Flash::set('success', 'Автомобиль обновлён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteCar(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $c = $this->myCarOr404((int) $p['id']);
        $this->deleteAllPhotos('car', (int) $c['id']);
        $this->repo->deleteCar((int) $c['id']);
        Flash::set('info', 'Автомобиль удалён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteCarPhoto(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $c = $this->myCarOr404((int) $p['id']);
        $this->deleteOnePhoto('car', (int) $c['id'], (int) ($p['img'] ?? 0));
        header('Location: /poselenie/moye-pomestie/avto/' . (int) $c['id'] . '/redaktirovat');
    }

    // ── Питомцы ─────────────────────────────────────────────────────────────
    public function showAddPet(): void
    {
        Auth::requireLogin();
        $this->myHouseholdOr404();
        View::render('profile/pet-form', ['pet' => null, 'images' => [], 'errors' => []], 'Новый питомец');
    }

    public function addPet(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validatePet();
        if ($errors) {
            View::render('profile/pet-form', ['pet' => $data, 'images' => [], 'errors' => $errors], 'Новый питомец');
            return;
        }
        $id = $this->repo->addPet((int) $h['id'], $data['name'], $data['kind'], $data['note']);
        $this->handleUploads('pet', $id);
        Flash::set('success', 'Питомец добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditPet(array $p): void
    {
        Auth::requireLogin();
        $pet = $this->myPetOr404((int) $p['id']);
        View::render('profile/pet-form', [
            'pet' => $pet,
            'images' => $this->images->listFor('pet', (int) $pet['id']),
            'errors' => [],
        ], 'Изменить питомца');
    }

    public function updatePet(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $pet = $this->myPetOr404((int) $p['id']);
        [$data, $errors] = $this->validatePet();
        if ($errors) {
            $data['id'] = $pet['id'];
            View::render('profile/pet-form', [
                'pet' => $data,
                'images' => $this->images->listFor('pet', (int) $pet['id']),
                'errors' => $errors,
            ], 'Изменить питомца');
            return;
        }
        $this->repo->updatePet((int) $pet['id'], $data['name'], $data['kind'], $data['note']);
        $this->handleUploads('pet', (int) $pet['id']);
        Flash::set('success', 'Питомец обновлён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deletePet(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $pet = $this->myPetOr404((int) $p['id']);
        $this->deleteAllPhotos('pet', (int) $pet['id']);
        $this->repo->deletePet((int) $pet['id']);
        Flash::set('info', 'Питомец удалён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deletePetPhoto(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $pet = $this->myPetOr404((int) $p['id']);
        $this->deleteOnePhoto('pet', (int) $pet['id'], (int) ($p['img'] ?? 0));
        header('Location: /poselenie/moye-pomestie/pitomec/' . (int) $pet['id'] . '/redaktirovat');
    }

    // ── Валидация ───────────────────────────────────────────────────────────
    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validateMember(): array
    {
        $s = static fn(string $k): string => trim($_POST[$k] ?? '');
        $name = $s('full_name');
        $email = $s('email');
        $errors = [];
        if (!Validator::length($name, 2, 200)) { $errors['full_name'] = 'Фамилия и имя: 2–200 символов.'; }
        if ($email !== '' && !Validator::email($email)) { $errors['email'] = 'Некорректный email.'; }
        $birthRaw = $s('birth_raw');
        return [[
            'full_name'      => $name,
            'birth_raw'      => $birthRaw,
            'birth_date'     => $this->parseBirth($birthRaw),
            'phone'          => $s('phone'),
            'vk'             => $s('vk'),
            'skills'         => $s('skills') !== '' ? $s('skills') : null,
            'community_role' => $s('community_role') !== '' ? $s('community_role') : null,
            'moved_text'     => $s('moved_text'),
            'residence'      => $s('residence'),
            'hometown'       => $s('hometown'),
            'email'          => $email,
            'comment'        => $s('comment') !== '' ? $s('comment') : null,
        ], $errors];
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function validateCar(): array
    {
        $title = trim($_POST['title'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Опишите автомобиль (2–200 символов).'; }
        return [[
            'title' => $title,
            'plate' => trim($_POST['plate'] ?? ''),
            'note'  => trim($_POST['note'] ?? ''),
        ], $errors];
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function validatePet(): array
    {
        $name = trim($_POST['name'] ?? '');
        $errors = [];
        if (!Validator::length($name, 1, 120)) { $errors['name'] = 'Кличка: 1–120 символов.'; }
        return [[
            'name' => $name,
            'kind' => trim($_POST['kind'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
        ], $errors];
    }

    private function parseBirth(string $s): ?string
    {
        if (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', trim($s), $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (checkdate($mo, $d, $y)) { return sprintf('%04d-%02d-%02d', $y, $mo, $d); }
        }
        return null;
    }

    private function today(): string { return date('d.m.Y'); }

    // ── Принадлежность (доступ только к своему) ─────────────────────────────
    private function myHouseholdOr404(): array
    {
        $h = $this->repo->householdByFamily(Auth::id());
        if (!$h) { $this->notFound('Поместье не найдено'); }
        return $h;
    }

    private function myMemberOr404(int $id): array
    {
        $h = $this->myHouseholdOr404();
        $m = $this->repo->memberById($id);
        if (!$m || (int) $m['household_id'] !== (int) $h['id']) { $this->notFound('Житель не найден'); }
        return $m;
    }

    private function myCarOr404(int $id): array
    {
        $h = $this->myHouseholdOr404();
        $c = $this->repo->carById($id);
        if (!$c || (int) $c['household_id'] !== (int) $h['id']) { $this->notFound('Автомобиль не найден'); }
        return $c;
    }

    private function myPetOr404(int $id): array
    {
        $h = $this->myHouseholdOr404();
        $pet = $this->repo->petById($id);
        if (!$pet || (int) $pet['household_id'] !== (int) $h['id']) { $this->notFound('Питомец не найден'); }
        return $pet;
    }

    // ── Фото (жители/авто/питомцы) — механика идентична дневникам ────────────
    /** Загружает выбранные фото: в Telegram-канал, с фолбэком на локальное хранилище. */
    private function handleUploads(string $ownerType, int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $files = $this->normalizeFiles($_FILES['photos']);
        $sort = count($this->images->listFor($ownerType, $ownerId));
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            $fileId = TelegramMedia::upload($file);
            if ($fileId !== null) {
                $this->images->add($ownerType, $ownerId, 'tg:' . $fileId, $sort++);
                continue;
            }
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) {
                $this->images->add($ownerType, $ownerId, $name, $sort++);
            } elseif ($err !== null) {
                Flash::set('error', $err);
            }
        }
    }

    /** Приводит массив $_FILES[multiple] к списку одиночных записей. */
    private function normalizeFiles(array $f): array
    {
        if (!is_array($f['name'])) { return [$f]; }
        $out = [];
        foreach ($f['name'] as $i => $_) {
            $out[] = [
                'name' => $f['name'][$i], 'type' => $f['type'][$i],
                'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ];
        }
        return $out;
    }

    /** Удаляет одно фото объекта (локальный файл, если не в Telegram-канале). */
    private function deleteOnePhoto(string $ownerType, int $ownerId, int $imgId): void
    {
        foreach ($this->images->listFor($ownerType, $ownerId) as $img) {
            if ((int) $img['id'] !== $imgId) { continue; }
            $this->unlinkLocal((string) $img['path']);
            $this->images->deleteById($imgId);
            Flash::set('info', 'Фото удалено.');
            break;
        }
    }

    /** Удаляет все фото объекта (файлы + строки) — при удалении жителя/авто/питомца. */
    private function deleteAllPhotos(string $ownerType, int $ownerId): void
    {
        foreach ($this->images->listFor($ownerType, $ownerId) as $img) {
            $this->unlinkLocal((string) $img['path']);
        }
        $this->images->deleteFor($ownerType, $ownerId);
    }

    /** Удаляет локальный файл фото (фото в Telegram-канале локально не хранится). */
    private function unlinkLocal(string $path): void
    {
        if (str_starts_with($path, 'tg:')) { return; }
        @unlink(rtrim((string) Config::get('uploads_dir'), '/\\') . '/' . basename($path));
    }

    private function csrfOrDie(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function notFound(string $title): never
    {
        http_response_code(404);
        View::render('public/notfound', [], $title);
        exit;
    }
}
