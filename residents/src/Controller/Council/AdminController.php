<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{Auth, CouncilAuth, Csrf, Flash, Validator, View, Config, Mailer};
use SkazResidents\Repository\CouncilMemberRepository;

/**
 * Управление аккаунтами членов совета — только для роли admin.
 * Приглашение-only: админ заводит аккаунт по email, система генерирует пароль
 * (показывается админу для передачи + отправляется члену письмом). Член совета
 * затем может сменить пароль сам. Также: ручной сброс пароля, блокировка/разблокировка.
 */
final class AdminController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireAdmin();
        View::render('council/admin/index', [
            'members' => $this->members->all(),
            'old'     => [],
            'errors'  => [],
        ], 'Участники совета', self::LAYOUT);
    }

    public function add(): void
    {
        $this->guard();
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $role  = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $errors = [];

        if (!Validator::email($email)) { $errors['email'] = 'Укажите корректный email.'; }
        if (!Validator::length($name, 2, 160)) { $errors['name'] = 'Имя: 2–160 символов.'; }
        if (!$errors && $this->members->findByEmail($email)) {
            $errors['email'] = 'Такой член совета уже есть.';
        }

        if ($errors) {
            View::render('council/admin/index', [
                'members' => $this->members->all(),
                'old'     => compact('email', 'name', 'role'),
                'errors'  => $errors,
            ], 'Участники совета', self::LAYOUT);
            return;
        }

        $password = $this->generatePassword();
        $this->members->create($email, Auth::hash($password), $name, $role);

        try {
            Mailer::send(
                $email, 'Доступ в раздел Попечительского совета — Сказочный Край',
                "Здравствуйте, {$name}!\n\nВам открыт доступ в раздел Попечительского совета на сайте.\n\nВход: " . Config::get('base_url') . "/sovet/vhod\nEmail: {$email}\nВременный пароль: {$password}\n\nПосле входа смените пароль в разделе «Пароль». Если письмо пришло по ошибке — просто игнорируйте его."
            );
        } catch (\Throwable $e) {
            error_log('council invite mail failed: ' . $e->getMessage());
        }

        Flash::set('success', "Член совета «{$name}» добавлен. Пароль: {$password} — передайте его лично (также отправлен на email).");
        header('Location: /sovet/upravlenie');
    }

    public function resetPassword(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $member = $this->members->findById($id);
        if ($member) {
            $password = $this->generatePassword();
            $this->members->updatePassword($id, Auth::hash($password));
            Flash::set('success', "Новый пароль для «{$member['name']}»: {$password} — передайте его члену совета.");
        }
        header('Location: /sovet/upravlenie');
    }

    public function toggleStatus(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $member = $this->members->findById($id);
        if ($member) {
            // Нельзя заблокировать самого себя — иначе можно потерять последний admin-доступ.
            if ($id === (int) CouncilAuth::id()) {
                Flash::set('error', 'Нельзя заблокировать собственный аккаунт.');
            } else {
                $new = $member['status'] === 'active' ? 'blocked' : 'active';
                $this->members->setStatus($id, $new);
                Flash::set('info', $new === 'blocked' ? 'Аккаунт заблокирован.' : 'Аккаунт разблокирован.');
            }
        }
        header('Location: /sovet/upravlenie');
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(5)); // 10 hex-символов (~40 бит), удобно продиктовать
    }

    private function guard(): void
    {
        CouncilAuth::requireAdmin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }
}
