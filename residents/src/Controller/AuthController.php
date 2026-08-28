<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Database};
use SkazResidents\Repository\FamilyRepository;

final class AuthController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository(),
        private \SkazResidents\Repository\ResetRepository $resets = new \SkazResidents\Repository\ResetRepository()
    ) {}

    public function showRegister(): void
    {
        View::render('auth/register', ['old' => [], 'errors' => []], 'Регистрация семьи');
    }

    public function register(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->throttled('reg:' . $ip)) {
            View::render('auth/register', ['old' => [], 'errors' => ['email' => 'Слишком много попыток регистрации. Попробуйте позже.']], 'Регистрация семьи');
            return;
        }
        $this->recordAttempt('reg:' . $ip, $ip);

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

        if ($this->throttled($email)) {
            View::render('auth/login', ['old' => compact('email'), 'error' => 'Слишком много попыток. Попробуйте позже.'], 'Вход для жителей');
            return;
        }

        $family = $this->families->findByEmail($email);
        $ok = $family && Auth::verify($pass, $family['password_hash']);

        if (!$ok) {
            $this->recordAttempt($email, $ip);
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

    public function showForgot(): void
    {
        View::render('auth/forgot', ['sent' => false], 'Восстановление пароля');
    }

    public function forgot(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->throttled('forgot:' . $ip)) {
            // Тихо показываем "отправлено" — не раскрываем ни факт троттлинга, ни существование email.
            View::render('auth/forgot', ['sent' => true], 'Восстановление пароля');
            return;
        }
        $this->recordAttempt('forgot:' . $ip, $ip);

        $email = trim($_POST['email'] ?? '');
        $family = Validator::email($email) ? $this->families->findByEmail($email) : null;

        // Всегда показываем "письмо отправлено" — не раскрываем, есть ли такой email.
        if ($family && $family['status'] === 'active') {
            $ttl = (int) Config::get('reset_ttl', 3600);
            $expires = date('Y-m-d H:i:s', time() + $ttl);
            $token = $this->resets->create((int) $family['id'], $expires);
            $link = Config::get('base_url') . '/poselenie/sbros?token=' . $token;
            try {
                \SkazResidents\Mailer::send(
                    $email, 'Восстановление пароля — Сказочный Край',
                    "Здравствуйте!\n\nЧтобы задать новый пароль, перейдите по ссылке (действует час):\n$link\n\nЕсли вы не запрашивали сброс — просто игнорируйте письмо."
                );
            } catch (\Throwable $e) {
                error_log('reset mail failed: ' . $e->getMessage());
            }
        }
        View::render('auth/forgot', ['sent' => true], 'Восстановление пароля');
    }

    public function showReset(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль'); return; }
        View::render('auth/reset', ['valid' => true, 'token' => $token, 'error' => null], 'Новый пароль');
    }

    public function reset(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $token = (string) ($_POST['token'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль'); return; }
        if (!Validator::password($pass)) {
            View::render('auth/reset', ['valid' => true, 'token' => $token, 'error' => 'Пароль не короче 8 символов.'], 'Новый пароль');
            return;
        }
        $this->families->updatePassword((int) $row['family_id'], Auth::hash($pass));
        $this->resets->delete($token);
        Flash::set('success', 'Пароль обновлён. Теперь войдите с новым паролем.');
        header('Location: /poselenie/vhod');
    }

    // Обобщённый троттлинг попыток (вход, регистрация, запрос сброса).
    // Ключ $key задаёт «корзину»: email для входа, "reg:<ip>"/"forgot:<ip>" для остального.
    // Переносимо между MariaDB и SQLite: сравниваем время попыток в PHP,
    // а не в SQL-выражении (у СУБД разный синтаксис работы с датами).
    private function throttled(string $key): bool
    {
        $cfg = Config::get('login_throttle');
        $st = Database::pdo()->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE email = ? ORDER BY attempted_at DESC LIMIT 50'
        );
        $st->execute([$key]);
        $cutoff = time() - (int) $cfg['window'];
        $recent = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $ts) {
            if (strtotime((string) $ts) >= $cutoff) { $recent++; }
        }
        return $recent >= (int) $cfg['max'];
    }

    private function recordAttempt(string $key, string $ip): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)'
        );
        $st->execute([$key, $ip, date('Y-m-d H:i:s')]);
    }
}
