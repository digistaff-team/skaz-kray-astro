<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{SessionGuard, CouncilAuth};

/** Блокировка, удаление и сброс пароля действуют на уже вошедших. */
final class SessionGuardTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = make_test_db();
        $_SESSION = [];
        $this->pdo->exec("INSERT INTO families (id,email,password_hash,name,status,role) VALUES
            (7,'a@sk.ru','HASH1','Крыловы','active','resident')");
        $this->pdo->exec("INSERT INTO council_members (id,email,password_hash,name,status,role) VALUES
            (3,'c@sk.ru','CHASH','Иван Петров','active','member')");
    }

    /** Как после Auth::login (session_regenerate_id в тестах не вызвать). */
    private function loginFamily(string $hash = 'HASH1'): void
    {
        $_SESSION['family_id'] = 7;
        $_SESSION['role'] = 'resident';
        $_SESSION['family_name'] = 'Крыловы';
        $_SESSION['family_pw'] = SessionGuard::fingerprint($hash);
    }

    private function loginCouncil(): void
    {
        $_SESSION['council_id'] = 3;
        $_SESSION['council_role'] = 'member';
        $_SESSION['council_name'] = 'Иван Петров';
        $_SESSION['council_pw'] = SessionGuard::fingerprint('CHASH');
    }

    public function test_active_session_survives(): void
    {
        $this->loginFamily();
        SessionGuard::check($this->pdo);
        $this->assertSame(7, $_SESSION['family_id']);
    }

    public function test_blocked_family_is_logged_out_but_council_stays(): void
    {
        $this->loginFamily();
        $this->loginCouncil();
        $this->pdo->exec("UPDATE families SET status = 'blocked' WHERE id = 7");
        SessionGuard::check($this->pdo);
        $this->assertArrayNotHasKey('family_id', $_SESSION);
        $this->assertArrayNotHasKey('role', $_SESSION);
        $this->assertSame(3, CouncilAuth::id());
    }

    public function test_pending_family_keeps_session(): void
    {
        // Вход через Telegram/MAX пускает pending — сверка не должна их выкидывать.
        $this->loginFamily();
        $this->pdo->exec("UPDATE families SET status = 'pending' WHERE id = 7");
        SessionGuard::check($this->pdo);
        $this->assertSame(7, $_SESSION['family_id']);
    }

    public function test_deleted_family_is_logged_out(): void
    {
        $this->loginFamily();
        $this->pdo->exec('DELETE FROM families WHERE id = 7');
        SessionGuard::check($this->pdo);
        $this->assertArrayNotHasKey('family_id', $_SESSION);
    }

    public function test_password_reset_revokes_session(): void
    {
        $this->loginFamily();
        $this->pdo->exec("UPDATE families SET password_hash = 'HASH2' WHERE id = 7");
        SessionGuard::check($this->pdo);
        $this->assertArrayNotHasKey('family_id', $_SESSION);
    }

    public function test_role_and_name_follow_database(): void
    {
        $this->loginFamily();
        $this->pdo->exec("UPDATE families SET role = 'editor', name = 'Семья Крыловых' WHERE id = 7");
        SessionGuard::check($this->pdo);
        $this->assertSame('editor', $_SESSION['role']);
        $this->assertSame('Семья Крыловых', $_SESSION['family_name']);
    }

    public function test_old_session_without_fingerprint_gets_one(): void
    {
        $this->loginFamily();
        unset($_SESSION['family_pw']);
        SessionGuard::check($this->pdo);
        $this->assertSame(7, $_SESSION['family_id']);
        $this->assertSame(SessionGuard::fingerprint('HASH1'), $_SESSION['family_pw']);
    }

    public function test_blocked_council_member_is_logged_out_but_family_stays(): void
    {
        $this->loginFamily();
        $this->loginCouncil();
        $this->pdo->exec("UPDATE council_members SET status = 'blocked' WHERE id = 3");
        SessionGuard::check($this->pdo);
        $this->assertNull(CouncilAuth::id());
        $this->assertArrayNotHasKey('council_pw', $_SESSION);
        $this->assertSame(7, $_SESSION['family_id']);
    }

    public function test_council_own_password_change_keeps_current_session(): void
    {
        $this->loginCouncil();
        $this->pdo->exec("UPDATE council_members SET password_hash = 'NEW' WHERE id = 3");
        CouncilAuth::passwordChanged('NEW');
        SessionGuard::check($this->pdo);
        $this->assertSame(3, CouncilAuth::id());
    }

    public function test_council_role_demotion_applies_immediately(): void
    {
        $this->loginCouncil();
        $_SESSION['council_role'] = 'admin';
        SessionGuard::check($this->pdo);
        $this->assertFalse(CouncilAuth::isAdmin());
    }
}
