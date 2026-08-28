<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Validator;

final class ValidatorTest extends TestCase
{
    public function test_email(): void
    {
        $this->assertTrue(Validator::email('a@b.ru'));
        $this->assertFalse(Validator::email('нет'));
    }

    public function test_required(): void
    {
        $this->assertTrue(Validator::required('x'));
        $this->assertFalse(Validator::required('   '));
    }

    public function test_length_counts_multibyte(): void
    {
        $this->assertTrue(Validator::length('привет', 3, 10));
        $this->assertFalse(Validator::length('да', 3, 10));
    }

    public function test_password_min_8(): void
    {
        $this->assertTrue(Validator::password('12345678'));
        $this->assertFalse(Validator::password('1234567'));
    }

    public function test_image_mime(): void
    {
        $this->assertTrue(Validator::imageMime('image/jpeg'));
        $this->assertTrue(Validator::imageMime('image/png'));
        $this->assertTrue(Validator::imageMime('image/webp'));
        $this->assertFalse(Validator::imageMime('application/pdf'));
    }
}
