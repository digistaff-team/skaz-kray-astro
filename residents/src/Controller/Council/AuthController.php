<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{Auth, CouncilAuth, Csrf, Flash, Validator, View, Config, Database, Mailer};
use SkazResidents\Repository\{CouncilMemberRepository, CouncilResetRepository};

/**
 * Вход/выход/восстановление/смена пароля для членов совета.
 * Самостоятельной регистрации нет — аккаунты заводит администратор (AdminController).
 * Пароль постоянный (bcrypt); «забыли пароль» — по email-ссылке.
 */
final class AuthController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilMemberRepository $members = new CouncilMemberRepository(),
        private CouncilResetRepository $resets = new CouncilResetRepository()
    ) {}

    public function showLogin(): void
    {
        if (CouncilAuth::id() !== null) { header('Location: /sovet'); return; }
        View::render('council/auth/login', ['old' => [], 'error' => null], 'Вход — Попечительский совет', self::LAYOUT);
    }

    public function login(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $email = trim($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->throttled('council:' . $email)) {
            View::render('council/auth/login', ['old' => compact('email'), 'error' => 'Слишком много попыток. Попробуйте позже.'], 'Вход — Попечительский совет', self::LAYOUT);
            return;
        }

        $member = $this->members->findByEmail($email);
        $ok = $member && Auth::verify($pass, $member['password_hash']);

        if (!$ok) {
            $this->recordAttempt('council:' . $email, $ip);
            View::render('council/auth/login', ['old' => compact('email'), 'error' => 'Неверный email или пароль.'], 'Вход — Попечительский совет', self::LAYOUT);
            return;
        }
        if ($member['status'] !== 'active') {
            View::render('council/auth/login', ['old' => compact('email'), 'error' => 'Доступ заблокирован. Обратитесь к администратору совета.'], 'Вход — Попечительский совет', self::LAYOUT);
            return;
        }

        CouncilAuth::login($member);
        header('Location: /sovet');
    }

    public function logout(): void
    {
        CouncilAuth::logout();
        header('Location: /sovet/vhod');
    }

    public function showForgot(): void
    {
        View::render('council/auth/forgot', ['sent' => false], 'Восстановление пароля', self::LAYOUT);
    }

    public function forgot(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->throttled('cforgot:' . $ip)) {
            View::render('council/auth/forgot', ['sent' => true], 'Восстановление пароля', self::LAYOUT);
            return;
        }
        $this->recordAttempt('cforgot:' . $ip, $ip);

        $email  = trim($_POST['email'] ?? '');
        $member = Validator::email($email) ? $this->members->findByEmail($email) : null;

        // Всегда показываем «письмо отправлено» — не раскрываем существование email.
        if ($member && $member['status'] === 'active') {
            $ttl     = (int) Config::get('reset_ttl', 3600);
            $expires = date('Y-m-d H:i:s', time() + $ttl);
            $token   = $this->resets->create((int) $member['id'], $expires);
            $link    = Config::get('base_url') . '/sovet/sbros?token=' . $token;
            try {
                Mailer::send(
                    $email, 'Восстановление пароля — Попечительский совет',
                    "Здравствуйте!\n\nЧтобы задать новый пароль для входа в раздел Попечительского совета, перейдите по ссылке (действует час):\n$link\n\nЕсли вы не запрашивали сброс — просто игнорируйте письмо."
                );
            } catch (\Throwable $e) {
                error_log('council reset mail failed: ' . $e->getMessage());
            }
        }
        View::render('council/auth/forgot', ['sent' => true], 'Восстановление пароля', self::LAYOUT);
    }

    public function showReset(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('council/auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль', self::LAYOUT); return; }
        View::render('council/auth/reset', ['valid' => true, 'token' => $token, 'error' => null], 'Новый пароль', self::LAYOUT);
    }

    public function reset(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $token = (string) ($_POST['token'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('council/auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль', self::LAYOUT); return; }
        if (!Validator::password($pass)) {
            View::render('council/auth/reset', ['valid' => true, 'token' => $token, 'error' => 'Пароль не короче 8 символов.'], 'Новый пароль', self::LAYOUT);
            return;
        }
        $this->members->updatePassword((int) $row['member_id'], Auth::hash($pass));
        $this->resets->delete($token);
        Flash::set('success', 'Пароль обновлён. Теперь войдите с новым паролем.');
        header('Location: /sovet/vhod');
    }

    // Смена пароля из личного раздела (для залогиненного члена совета).
    public function showPassword(): void
    {
        CouncilAuth::requireLogin();
        View::render('council/password', ['error' => null], 'Смена пароля', self::LAYOUT);
    }

    public function changePassword(): void
    {
        CouncilAuth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $id      = (int) CouncilAuth::id();
        $current = (string) ($_POST['current'] ?? '');
        $next    = (string) ($_POST['password'] ?? '');
        $member  = $this->members->findById($id);

        if (!$member || !Auth::verify($current, $member['password_hash'])) {
            View::render('council/password', ['error' => 'Текущий пароль указан неверно.'], 'Смена пароля', self::LAYOUT);
            return;
        }
        if (!Validator::password($next)) {
            View::render('council/password', ['error' => 'Новый пароль не короче 8 символов.'], 'Смена пароля', self::LAYOUT);
            return;
        }
        $this->members->updatePassword($id, Auth::hash($next));
        Flash::set('success', 'Пароль изменён.');
        header('Location: /sovet');
    }

    // --- Троттлинг (общая таблица login_attempts, ключи council:/cforgot:) ---
    private function throttled(string $key): bool
    {
        $cfg = Config::get('login_throttle');
        $st = Database::pdo()->prepare(
            'SELECT attempted_at FROM login_attempts WHERE email = ? ORDER BY attempted_at DESC LIMIT 50'
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
