<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Database};
use SkazResidents\Repository\FamilyRepository;

final class AuthController
{
    public function __construct(private FamilyRepository $families = new FamilyRepository()) {}

    public function showRegister(): void
    {
        View::render('auth/register', ['old' => [], 'errors' => []], 'Регистрация семьи');
    }

    public function register(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $errors = [];

        if (!Validator::email($email)) { $errors['email'] = 'Укажите корректный email.'; }
        if (!Validator::length($name, 2, 160)) { $errors['name'] = 'Название семьи/поместья: 2–160 символов.'; }
        if (!Validator::password($pass)) { $errors['password'] = 'Пароль не короче 8 символов.'; }
        if (!$errors && $this->families->findByEmail($email)) {
            $errors['email'] = 'Такой email уже зарегистрирован.';
        }

        if ($errors) {
            View::render('auth/register', ['old' => compact('email', 'name'), 'errors' => $errors], 'Регистрация семьи');
            return;
        }

        $this->families->createPending($email, Auth::hash($pass), $name);
        Flash::set('success', 'Заявка отправлена. После одобрения редактором вы сможете войти.');
        header('Location: /poselenie/vhod');
    }

    public function showLogin(): void
    {
        View::render('auth/login', ['old' => [], 'error' => null], 'Вход для жителей');
    }

    public function login(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $email = trim($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->isThrottled($email)) {
            View::render('auth/login', ['old' => compact('email'), 'error' => 'Слишком много попыток. Попробуйте позже.'], 'Вход для жителей');
            return;
        }

        $family = $this->families->findByEmail($email);
        $ok = $family && Auth::verify($pass, $family['password_hash']);

        if (!$ok) {
            $this->recordFailure($email, $ip);
            View::render('auth/login', ['old' => compact('email'), 'error' => 'Неверный email или пароль.'], 'Вход для жителей');
            return;
        }
        if ($family['status'] !== 'active') {
            $msg = $family['status'] === 'pending'
                ? 'Заявка ещё не одобрена редактором.'
                : 'Доступ заблокирован. Обратитесь к редактору поселения.';
            View::render('auth/login', ['old' => compact('email'), 'error' => $msg], 'Вход для жителей');
            return;
        }

        Auth::login($family);
        header('Location: /poselenie/');
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /poselenie/vhod');
    }

    // Переносимо между MariaDB и SQLite: сравниваем время попыток в PHP,
    // а не в SQL-выражении (у СУБД разный синтаксис работы с датами).
    private function isThrottled(string $email): bool
    {
        $cfg = Config::get('login_throttle');
        $st = Database::pdo()->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE email = ? ORDER BY attempted_at DESC LIMIT 50'
        );
        $st->execute([$email]);
        $cutoff = time() - (int) $cfg['window'];
        $recent = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $ts) {
            if (strtotime((string) $ts) >= $cutoff) { $recent++; }
        }
        return $recent >= (int) $cfg['max'];
    }

    private function recordFailure(string $email, string $ip): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)'
        );
        $st->execute([$email, $ip, date('Y-m-d H:i:s')]);
    }
}
