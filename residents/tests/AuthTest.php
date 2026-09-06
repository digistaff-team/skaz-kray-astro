<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Auth;

final class AuthTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function test_hash_and_verify(): void
    {
        $h = Auth::hash('секрет123');
        $this->assertNotSame('секрет123', $h);
        $this->assertTrue(Auth::verify('секрет123', $h));
        $this->assertFalse(Auth::verify('другой', $h));
    }

    public function test_session_state(): void
    {
        $this->assertNull(Auth::id());
        $_SESSION['family_id'] = 7;
        $_SESSION['role'] = 'editor';
        $this->assertSame(7, Auth::id());
        $this->assertTrue(Auth::isEditor());
        $this->assertFalse(Auth::isAdmin());
    }

    public function test_admin_is_superset_of_editor(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(Auth::isAdmin());
        $this->assertTrue(Auth::isEditor()); // админ может всё, что редактор

        $_SESSION['role'] = 'resident';
        $this->assertFalse(Auth::isAdmin());
        $this->assertFalse(Auth::isEditor());
    }
}
