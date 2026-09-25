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

    public function test_editor_cannot_manage_editor_or_admin_accounts(): void
    {
        $_SESSION['family_id'] = 7;
        $_SESSION['role'] = 'editor';
        $this->assertTrue(Auth::canManageAccount(['role' => 'resident']));
        $this->assertFalse(Auth::canManageAccount(['role' => 'editor']));
        $this->assertFalse(Auth::canManageAccount(['role' => 'admin']));
    }

    public function test_admin_can_manage_any_account(): void
    {
        $_SESSION['family_id'] = 1;
        $_SESSION['role'] = 'admin';
        $this->assertTrue(Auth::canManageAccount(['role' => 'admin']));
        $this->assertTrue(Auth::canManageAccount(['role' => 'editor']));
    }

    public function test_resident_cannot_manage_accounts(): void
    {
        $_SESSION['family_id'] = 9;
        $_SESSION['role'] = 'resident';
        $this->assertFalse(Auth::canManageAccount(['role' => 'resident']));
    }

    public function test_platform_is_remembered_and_whitelisted(): void
    {
        // Пусто, пока не входили: сессии прошлых версий признака не имеют, и
        // клиент в этом случае опознаёт мессенджер сам (см. assets/tg-webapp.js).
        $this->assertSame('', Auth::platform());

        Auth::setPlatform('max');
        $this->assertSame('max', Auth::platform());

        Auth::setPlatform('tg');
        $this->assertSame('tg', Auth::platform());

        // Значение уезжает в разметку — что угодно принимать нельзя.
        Auth::setPlatform('<script>');
        $this->assertSame('web', Auth::platform());
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

    public function test_next_accepts_only_portal_paths(): void
    {
        $this->assertSame('/poselenie/instrumenty/12', Auth::safeNext('/poselenie/instrumenty/12'));
        $this->assertSame('/poselenie/poezdki?filter=soon', Auth::safeNext('/poselenie/poezdki?filter=soon'));
    }

    public function test_next_is_not_an_open_redirect(): void
    {
        // Всё, что могло бы увести с сайта или разорвать заголовок, — отбрасываем.
        foreach ([
            '',
            'https://evil.example/poselenie/',
            '//evil.example/poselenie/',
            '/sovet',                                // чужой раздел — не наш вход
            '/poselenie',                            // без завершающего «/» — не путь внутри портала
            "/poselenie/app\r\nSet-Cookie: x=1",     // внедрение заголовка
            '/poselenie/\\evil.example',              // обратный слеш браузеры читают как «/»
        ] as $bad) {
            $this->assertNull(Auth::safeNext($bad), 'Пропущено: ' . json_encode($bad));
        }
    }
}
