<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Env;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        putenv('SKAZ_TEST_TOKEN');
        putenv('SKAZ_TEST_PRESET');
        unset($_ENV['SKAZ_TEST_TOKEN'], $_ENV['SKAZ_TEST_PRESET']);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        putenv('SKAZ_TEST_TOKEN');
        putenv('SKAZ_TEST_PRESET');
    }

    public function test_parses_keys_comments_and_quotes(): void
    {
        file_put_contents($this->file, "# коммент\n\nSKAZ_TEST_TOKEN = \"abc:123\"\n");
        Env::load($this->file);
        $this->assertSame('abc:123', getenv('SKAZ_TEST_TOKEN'));
    }

    public function test_does_not_override_real_env(): void
    {
        putenv('SKAZ_TEST_PRESET=real');
        file_put_contents($this->file, "SKAZ_TEST_PRESET=fromfile\n");
        Env::load($this->file);
        $this->assertSame('real', getenv('SKAZ_TEST_PRESET'));
    }

    public function test_missing_file_is_noop(): void
    {
        Env::load('/nonexistent/path/.env'); // не бросает
        $this->assertFalse(getenv('SKAZ_TEST_TOKEN'));
    }
}
