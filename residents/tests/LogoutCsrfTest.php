<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Config, Csrf, CouncilAuth};
use SkazResidents\Controller\AuthController;
use SkazResidents\Controller\Council\AuthController as CouncilAuthController;

/** Выход только с CSRF-токеном: <img src=".../vyhod"> на чужой странице не разлогинит. */
final class LogoutCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set([]);
        make_test_db();
        $_SESSION = ['family_id' => 7, 'role' => 'resident', 'family_name' => 'x',
                     'council_id' => 3, 'council_role' => 'member', 'council_name' => 'y'];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
    }

    public function test_resident_logout_without_token_only_asks(): void
    {
        ob_start();
        (new AuthController())->logout();
        $html = (string) ob_get_clean();
        $this->assertSame(7, $_SESSION['family_id']);
        $this->assertStringContainsString('action="/poselenie/vyhod"', $html);
    }

    public function test_resident_logout_with_token(): void
    {
        $_GET['t'] = Csrf::token();
        (new AuthController())->logout();
        $this->assertArrayNotHasKey('family_id', $_SESSION);
    }

    public function test_council_logout_needs_token_and_keeps_resident(): void
    {
        ob_start();
        (new CouncilAuthController())->logout();
        ob_end_clean();
        $this->assertSame(3, CouncilAuth::id());

        $_POST['_csrf'] = Csrf::token();
        (new CouncilAuthController())->logout();
        $this->assertNull(CouncilAuth::id());
        $this->assertSame(7, $_SESSION['family_id']);
    }
}
