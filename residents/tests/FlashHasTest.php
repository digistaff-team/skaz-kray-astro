<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Flash;

final class FlashHasTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function test_has_finds_type_without_taking(): void
    {
        $this->assertFalse(Flash::has('error'));
        Flash::set('info', 'a');
        Flash::set('error', 'b');
        $this->assertTrue(Flash::has('error'));
        $this->assertFalse(Flash::has('success'));
        $this->assertCount(2, Flash::take(), 'has() ничего не забирает');
    }
}
