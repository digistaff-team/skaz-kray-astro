<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View};
use SkazResidents\Repository\HouseholdProfileRepository;

/**
 * «Наше поместье» — личный кабинет семьи. Вошедший под аккаунтом поместья
 * (households.family_id = Auth::id()) видит и правит ТОЛЬКО своё поместье:
 * данные жителей (добавить/изменить/удалить), автомобили, название поместья.
 * Правки применяются сразу. Поляна/участок/статус — только просмотр.
 */
final class ProfileController
{
    public function __construct(
        private HouseholdProfileRepository $repo = new HouseholdProfileRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $h = $this->repo->householdByFamily(Auth::id());
        if (!$h) {
            header('Location: /poselenie/moye-pomestie/vybor'); // выбрать своё поместье
            return;
        }
        View::render('profile/index', [
            'household' => $h,
            'members'   => $this->repo->members((int) $h['id']),
            'cars'      => $this->repo->cars((int) $h['id']),
            'pets'      => $this->repo->pets((int) $h['id']),
        ], 'Наше поместье');
    }

    // ── Выбор своего поместья (привязка аккаунта) ───────────────────────────
    public function showClaim(): void
    {
        Auth::requireLogin();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        // Список группируется по полянам в шаблоне (поиск не нужен — житель
        // находит своё поместье, раскрыв свою поляну).
        View::render('profile/claim', ['households' => $this->repo->listClaimable()], 'Выбор поместья');
    }

    public function showClaimConfirm(array $p): void
    {
        Auth::requireLogin();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        $id = (int) $p['id'];
        $h = $this->repo->householdById($id);
        if (!$h || !$this->repo->isClaimable($id)) { $this->notFound('Поместье недоступно для выбора'); }
        // ПДн жителей не показываем — подтверждаем вводом фамилии (если жители есть).
        View::render('profile/claim-confirm', ['household' => $h, 'hasMembers' => $this->repo->members($id) !== []], 'Подтверждение поместья');
    }

    public function claim(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        if ($this->repo->householdByFamily(Auth::id())) { header('Location: /poselenie/moye-pomestie'); return; }
        $id = (int) $p['id'];
        $h = $this->repo->householdById($id);
        if (!$h || !$this->repo->isClaimable($id)) {
            Flash::set('error', 'Это поместье уже привязано к другому аккаунту. Обратитесь к редактору поселения.');
            header('Location: /poselenie/moye-pomestie/vybor');
            return;
        }
        // Проверка принадлежности: если в поместье есть жители — подтверждаем фамилией.
        if ($this->repo->members($id) !== []) {
            $surname = trim($_POST['surname'] ?? '');
            if (!$this->repo->surnameMatchesHousehold($id, $surname)) {
                Flash::set('error', 'Такой фамилии нет среди жителей этого поместья. Проверьте написание или обратитесь к редактору.');
                header('Location: /poselenie/moye-pomestie/vybor/' . $id);
                return;
            }
        }
        if (!$this->repo->claim($id, Auth::id())) {
            Flash::set('error', 'Это поместье уже привязано к другому аккаунту. Обратитесь к редактору поселения.');
            header('Location: /poselenie/moye-pomestie/vybor');
            return;
        }
        Flash::set('success', 'Поместье привязано к вашему аккаунту — теперь можно проверять и править данные.');
        header('Location: /poselenie/moye-pomestie');
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
        View::render('profile/member-form', ['member' => null, 'errors' => []], 'Новый житель');
    }

    public function addMember(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validateMember();
        if ($errors) {
            View::render('profile/member-form', ['member' => $data, 'errors' => $errors], 'Новый житель');
            return;
        }
        $this->repo->addMember((int) $h['id'], $data, $this->today());
        Flash::set('success', 'Житель добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditMember(array $p): void
    {
        Auth::requireLogin();
        $m = $this->myMemberOr404((int) $p['id']);
        View::render('profile/member-form', ['member' => $m, 'errors' => []], 'Изменить данные жителя');
    }

    public function updateMember(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $m = $this->myMemberOr404((int) $p['id']);
        [$data, $errors] = $this->validateMember();
        if ($errors) {
            $data['id'] = $m['id'];
            View::render('profile/member-form', ['member' => $data, 'errors' => $errors], 'Изменить данные жителя');
            return;
        }
        $this->repo->updateMember((int) $m['id'], $data, $this->today());
        Flash::set('success', 'Данные жителя обновлены.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteMember(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $m = $this->myMemberOr404((int) $p['id']);
        $this->repo->deleteMember((int) $m['id']);
        Flash::set('info', 'Житель удалён из поместья.');
        header('Location: /poselenie/moye-pomestie');
    }

    // ── Автомобили ──────────────────────────────────────────────────────────
    public function showAddCar(): void
    {
        Auth::requireLogin();
        $this->myHouseholdOr404();
        View::render('profile/car-form', ['car' => null, 'errors' => []], 'Новый автомобиль');
    }

    public function addCar(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validateCar();
        if ($errors) {
            View::render('profile/car-form', ['car' => $data, 'errors' => $errors], 'Новый автомобиль');
            return;
        }
        $this->repo->addCar((int) $h['id'], $data['title'], $data['plate'], $data['note']);
        Flash::set('success', 'Автомобиль добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditCar(array $p): void
    {
        Auth::requireLogin();
        $c = $this->myCarOr404((int) $p['id']);
        View::render('profile/car-form', ['car' => $c, 'errors' => []], 'Изменить автомобиль');
    }

    public function updateCar(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $c = $this->myCarOr404((int) $p['id']);
        [$data, $errors] = $this->validateCar();
        if ($errors) {
            $data['id'] = $c['id'];
            View::render('profile/car-form', ['car' => $data, 'errors' => $errors], 'Изменить автомобиль');
            return;
        }
        $this->repo->updateCar((int) $c['id'], $data['title'], $data['plate'], $data['note']);
        Flash::set('success', 'Автомобиль обновлён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deleteCar(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $c = $this->myCarOr404((int) $p['id']);
        $this->repo->deleteCar((int) $c['id']);
        Flash::set('info', 'Автомобиль удалён.');
        header('Location: /poselenie/moye-pomestie');
    }

    // ── Питомцы ─────────────────────────────────────────────────────────────
    public function showAddPet(): void
    {
        Auth::requireLogin();
        $this->myHouseholdOr404();
        View::render('profile/pet-form', ['pet' => null, 'errors' => []], 'Новый питомец');
    }

    public function addPet(): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $h = $this->myHouseholdOr404();
        [$data, $errors] = $this->validatePet();
        if ($errors) {
            View::render('profile/pet-form', ['pet' => $data, 'errors' => $errors], 'Новый питомец');
            return;
        }
        $this->repo->addPet((int) $h['id'], $data['name'], $data['kind'], $data['note']);
        Flash::set('success', 'Питомец добавлен.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function showEditPet(array $p): void
    {
        Auth::requireLogin();
        $pet = $this->myPetOr404((int) $p['id']);
        View::render('profile/pet-form', ['pet' => $pet, 'errors' => []], 'Изменить питомца');
    }

    public function updatePet(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $pet = $this->myPetOr404((int) $p['id']);
        [$data, $errors] = $this->validatePet();
        if ($errors) {
            $data['id'] = $pet['id'];
            View::render('profile/pet-form', ['pet' => $data, 'errors' => $errors], 'Изменить питомца');
            return;
        }
        $this->repo->updatePet((int) $pet['id'], $data['name'], $data['kind'], $data['note']);
        Flash::set('success', 'Питомец обновлён.');
        header('Location: /poselenie/moye-pomestie');
    }

    public function deletePet(array $p): void
    {
        Auth::requireLogin();
        $this->csrfOrDie();
        $pet = $this->myPetOr404((int) $p['id']);
        $this->repo->deletePet((int) $pet['id']);
        Flash::set('info', 'Питомец удалён.');
        header('Location: /poselenie/moye-pomestie');
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
