<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase
{
    public function test_php_version_is_8_3_or_newer(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
    }
}
