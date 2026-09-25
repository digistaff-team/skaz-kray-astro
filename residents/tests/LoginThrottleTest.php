<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Config, LoginThrottle};

/** Лимит неудачных входов: по email и по IP (перебор одного пароля по многим адресам). */
final class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        make_test_db();
        Config::set(['login_throttle' => ['max' => 5, 'window' => 900]]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_email_limit(): void
    {
        for ($i = 0; $i < 4; $i++) { LoginThrottle::recordLoginFailure('', 'a@sk.ru'); }
        $this->assertFalse(LoginThrottle::loginBlocked('', 'a@sk.ru'));
        LoginThrottle::recordLoginFailure('', 'a@sk.ru');
        $this->assertTrue(LoginThrottle::loginBlocked('', 'a@sk.ru'));
        $this->assertFalse(LoginThrottle::loginBlocked('', 'b@sk.ru'));
    }

    public function test_spraying_many_emails_from_one_ip_is_blocked(): void
    {
        $max = LoginThrottle::IP_LIMIT['max'];
        for ($i = 0; $i < $max; $i++) { LoginThrottle::recordLoginFailure('', "user{$i}@sk.ru"); }
        $this->assertTrue(LoginThrottle::loginBlocked('', 'fresh@sk.ru'));

        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';   // другой IP — не задет
        $this->assertFalse(LoginThrottle::loginBlocked('', 'fresh@sk.ru'));
    }

    public function test_scopes_are_independent(): void
    {
        for ($i = 0; $i < 5; $i++) { LoginThrottle::recordLoginFailure('council:', 'a@sk.ru'); }
        $this->assertTrue(LoginThrottle::loginBlocked('council:', 'a@sk.ru'));
        $this->assertFalse(LoginThrottle::loginBlocked('', 'a@sk.ru'));
    }
}
