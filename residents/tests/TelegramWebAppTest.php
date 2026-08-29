<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\TelegramWebApp;

final class TelegramWebAppTest extends TestCase
{
    private const TOKEN = '123456:TEST-BOT-TOKEN';

    /** Собирает валидный initData с корректной подписью (как это делает Telegram). */
    private function makeInitData(array $fields, string $token): string
    {
        $pairs = [];
        foreach ($fields as $k => $v) { $pairs[] = $k . '=' . $v; }
        sort($pairs);
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $pairs), $secret);
        // initData — query-строка (url-encoded значения) + hash.
        $parts = [];
        foreach ($fields as $k => $v) { $parts[] = rawurlencode($k) . '=' . rawurlencode($v); }
        $parts[] = 'hash=' . $hash;
        return implode('&', $parts);
    }

    public function test_valid_initdata_returns_user(): void
    {
        $user = json_encode(['id' => 111, 'first_name' => 'Иван', 'last_name' => 'Петров', 'username' => 'ivan']);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'user' => $user], self::TOKEN);
        $res = TelegramWebApp::verify($initData, self::TOKEN);
        $this->assertNotNull($res);
        $this->assertSame('111', $res['id']);
        $this->assertSame('Иван', $res['first_name']);
        $this->assertSame('ivan', $res['username']);
    }

    public function test_wrong_token_rejected(): void
    {
        $user = json_encode(['id' => 111, 'first_name' => 'Иван']);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'user' => $user], self::TOKEN);
        $this->assertNull(TelegramWebApp::verify($initData, 'other:TOKEN'));
    }

    public function test_tampered_data_rejected(): void
    {
        $user = json_encode(['id' => 111]);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'user' => $user], self::TOKEN);
        // Подменяем user после подписи — хеш больше не сходится.
        $tampered = str_replace(rawurlencode($user), rawurlencode(json_encode(['id' => 999])), $initData);
        $this->assertNull(TelegramWebApp::verify($tampered, self::TOKEN));
    }

    public function test_stale_auth_date_rejected(): void
    {
        $user = json_encode(['id' => 111]);
        $old = (string) (time() - 90000); // >24ч
        $initData = $this->makeInitData(['auth_date' => $old, 'user' => $user], self::TOKEN);
        $this->assertNull(TelegramWebApp::verify($initData, self::TOKEN));
    }

    public function test_signature_field_variant_accepted(): void
    {
        // Клиент прислал поле signature — подпись считается канонично (со всеми
        // полями кроме hash). verify должен принять (проверяет обе трактовки).
        $user = json_encode(['id' => 111, 'first_name' => 'А']);
        $initData = $this->makeInitData(['auth_date' => (string) time(), 'signature' => 'abc', 'user' => $user], self::TOKEN);
        $this->assertNotNull(TelegramWebApp::verify($initData, self::TOKEN));
    }

    public function test_empty_inputs(): void
    {
        $this->assertNull(TelegramWebApp::verify('', self::TOKEN));
        $this->assertNull(TelegramWebApp::verify('auth_date=1&hash=x', ''));
    }
}
