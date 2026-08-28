<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\CouncilAuth;

final class CouncilAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_id_null_when_logged_out(): void
    {
        $this->assertNull(CouncilAuth::id());
        $this->assertFalse(CouncilAuth::isAdmin());
    }

    public function test_reads_session_keys(): void
    {
        // Ключи совета независимы от family_* (раздел жителей).
        $_SESSION['council_id'] = 7;
        $_SESSION['council_role'] = 'admin';
        $_SESSION['council_name'] = 'Сергей Шубин';
        $this->assertSame(7, CouncilAuth::id());
        $this->assertTrue(CouncilAuth::isAdmin());
        $this->assertSame('Сергей Шубин', CouncilAuth::name());
    }

    public function test_logout_clears_only_council_keys(): void
    {
        $_SESSION['council_id'] = 7;
        $_SESSION['family_id'] = 3; // вход жителя не должен пострадать
        CouncilAuth::logout();
        $this->assertNull(CouncilAuth::id());
        $this->assertSame(3, $_SESSION['family_id']);
    }
}
