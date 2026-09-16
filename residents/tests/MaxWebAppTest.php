<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\MaxWebApp;

final class MaxWebAppTest extends TestCase
{
    private const TOKEN = 'max-test-bot-token';

    /** Собирает валидный initData с подписью MAX (schema dev.max.ru/docs/webapps/validation). */
    private function makeInitData(array $fields, string $token): string
    {
        $pairs = [];
        foreach ($fields as $k => $v) { $pairs[] = $k . '=' . $v; }
        sort($pairs);
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $pairs), $secret);
        $parts = [];
        foreach ($fields as $k => $v) { $parts[] = rawurlencode($k) . '=' . rawurlencode($v); }
        $parts[] = 'hash=' . $hash;
        return implode('&', $parts);
    }

    public function test_valid_initdata_returns_user(): void
    {
        $user = json_encode(['id' => 643900558807, 'first_name' => 'Александр', 'last_name' => 'Бобков', 'username' => 'bobkov']);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'query_id' => 'q1', 'user' => $user], self::TOKEN);
        $res = MaxWebApp::verify($initData, self::TOKEN);
        $this->assertNotNull($res);
        $this->assertSame('643900558807', $res['id']);
        $this->assertSame('Александр', $res['first_name']);
        $this->assertSame('bobkov', $res['username']);
    }

    public function test_wrong_token_rejected(): void
    {
        $user = json_encode(['id' => 111, 'first_name' => 'Иван']);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'user' => $user], self::TOKEN);
        $this->assertNull(MaxWebApp::verify($initData, 'other-token'));
    }

    public function test_tampered_data_rejected(): void
    {
        $user = json_encode(['id' => 111]);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'user' => $user], self::TOKEN);
        $tampered = str_replace(rawurlencode($user), rawurlencode(json_encode(['id' => 999])), $initData);
        $this->assertNull(MaxWebApp::verify($tampered, self::TOKEN));
    }

    public function test_stale_auth_date_rejected(): void
    {
        $user = json_encode(['id' => 111]);
        $old = (string) (time() - 90000); // >24ч
        $initData = $this->makeInitData(['auth_date' => $old, 'user' => $user], self::TOKEN);
        $this->assertNull(MaxWebApp::verify($initData, self::TOKEN));
    }

    public function test_empty_inputs(): void
    {
        $this->assertNull(MaxWebApp::verify('', self::TOKEN));
        $this->assertNull(MaxWebApp::verify('auth_date=1&hash=x', ''));
    }
}
