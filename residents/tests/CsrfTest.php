<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Csrf;

final class CsrfTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function test_token_is_stable_within_session(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a)); // 32 байта hex
    }

    public function test_check_accepts_valid_and_rejects_invalid(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::check($t));
        $this->assertFalse(Csrf::check('nope'));
        $this->assertFalse(Csrf::check(null));
    }
}
