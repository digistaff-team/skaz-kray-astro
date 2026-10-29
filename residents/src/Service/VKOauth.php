<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Config;
use SkazResidents\Repository\VkOauthTokenRepository;

/**
 * Ключ доступа пользователя VK ID для чтения стены ВК-группы «Вече».
 *
 * Зачем отдельный сервис: методы стены (wall.get) VK не отдаёт групповому
 * ключу вообще («Group authorization failed: method is unavailable with group
 * auth»), а анонимных вызовов у API нет. Нужен именно user-токен, а он живёт
 * 1 час — значит, кроме получения по коду, обязательна связка с refresh_token.
 *
 * Схема (Authorization Code Flow + PKCE, актуальная политика VK):
 *   1. authorizationUrl() — ссылка, которую открывает админ в браузере;
 *   2. VK возвращает code на redirect_uri (см. Controller\VkOauthController);
 *   3. exchange() — code → access_token + refresh_token (PKCE code_verifier);
 *   4. accessToken() — выдать живой токен, при истечении обновить по refresh.
 *
 * refresh_token VK ротирует при каждом обновлении: всегда сохраняем новый,
 * иначе следующее обновление не сработает.
 *
 * Сеть — через инъекцию callable (образец: Service\VK, Service\WaterAlert):
 * в тестах подменяется, в бою — HTTP-запросы к id.vk.ru.
 */
final class VKOauth
{
    /** За сколько до истечения обновляем токен заранее, секунд. */
    private const REFRESH_SKEW = 300;

    private const AUTH_URL  = 'https://id.vk.ru/oauth2/auth';
    private const TOKEN_URL = 'https://id.vk.ru/oauth2/auth';
    private const API_VERSION = '5.199';

    /** @var (callable(string,array<string,string>):?string)|null POST: (url, form) → тело */
    private $http;

    private VkOauthTokenRepository $tokens;

    /**
     * @param (callable(string,array<string,string>):?string)|null $http подмена HTTP в тестах
     */
    public function __construct(?callable $http = null, ?VkOauthTokenRepository $tokens = null)
    {
        $this->http = $http;
        $this->tokens = $tokens ?? new VkOauthTokenRepository();
    }

    /** client_id приложения из конфига (vk_vechesk.client_id). */
    public static function clientId(): string
    {
        $conf = Config::get('vk_vechesk');
        return is_array($conf) ? trim((string) ($conf['client_id'] ?? '')) : '';
    }

    /** client_secret: секрет — из окружения, в config.php не пишем. */
    public static function clientSecret(): string
    {
        $env = trim((string) (getenv('VK_VECHESK_CLIENT_SECRET') ?: ''));
        if ($env !== '') { return $env; }
        $conf = Config::get('vk_vechesk');
        return is_array($conf) ? trim((string) ($conf['client_secret'] ?? '')) : '';
    }

    /** redirect_uri — должен совпадать с настройками приложения символ в символ. */
    public static function redirectUri(): string
    {
        $conf = Config::get('vk_vechesk');
        $uri  = is_array($conf) ? trim((string) ($conf['redirect_uri'] ?? '')) : '';
        if ($uri !== '') { return $uri; }
        $base = rtrim((string) Config::get('base_url', 'https://skaz-kray.ru'), '/');
        return $base . '/poselenie/vk-vechesk/oauth-callback';
    }

    /** Настроен ли вход (иначе страница подключения объяснит, что задать). */
    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * Пара PKCE: verifier (43–128 символов) и его S256-challenge.
     * @return array{verifier:string,challenge:string}
     */
    public static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Ссылка авторизации. Права минимальные: только wall (чтение стены).
     * @param string $state случайная строка, сверяется на callback
     */
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'             => self::clientId(),
            'response_type'         => 'code',
            'redirect_uri'          => self::redirectUri(),
            'scope'                 => 'wall',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'v'                     => self::API_VERSION,
        ]);
    }

    /**
     * Обменять code на токены. Сохраняет связку и возвращает её.
     * @return array{access_token:string,refresh_token:string,expires_in:int,user_id:int}
     * @throws \RuntimeException VK ответил ошибкой или не настроен вход
     */
    public function exchange(string $code, string $codeVerifier, string $now): array
    {
        $res = $this->request([
            'grant_type'    => 'authorization_code',
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => self::redirectUri(),
            'scope'         => 'wall',
        ]);
        $this->persist($res, $now);
        return $res;
    }

    /**
     * Живой access_token для вызовов стены. При истечении (или если до конца
     * меньше REFRESH_SKEW) обновляет по refresh_token.
     *
     * @throws \RuntimeException токенов нет или VK отказал в обновлении —
     *         вызывающий (cron) обязан упасть заметно и не двигать курсор
     */
    public function accessToken(string $now): string
    {
        $row = $this->tokens->tokens();
        if (!$row) {
            throw new \RuntimeException('VK: вход не выполнен — откройте /poselenie/vk-vechesk и подключите доступ');
        }
        if (!$this->expired((string) $row['expires_at'], $now)) {
            return (string) $row['access_token'];
        }

        try {
            $res = $this->request([
                'grant_type'    => 'refresh_token',
                'client_id'     => self::clientId(),
                'client_secret' => self::clientSecret(),
                'refresh_token' => (string) $row['refresh_token'],
                'scope'         => 'wall',
            ]);
        } catch (\RuntimeException $e) {
            $this->tokens->markError($e->getMessage(), $now);
            throw $e;
        }
        $this->persist($res, $now);
        return (string) $res['access_token'];
    }

    /** Истёк ли токен с учётом запаса (expires_at — 'Y-m-d H:i:s', время приложения). */
    public function expired(string $expiresAt, string $now): bool
    {
        $exp = strtotime($expiresAt);
        $cur = strtotime($now);
        if ($exp === false || $cur === false) { return true; }
        return $exp - self::REFRESH_SKEW <= $cur;
    }

    /** Когда истекает сохранённый токен ('' — токенов нет). */
    public function expiresAt(): string
    {
        $row = $this->tokens->tokens();
        return $row ? (string) $row['expires_at'] : '';
    }

    // ---- internals -------------------------------------------------------

    /**
     * POST формы на id.vk.ru и разбор ответа. Ошибки VK ({error, error_description})
     * — исключение: молча «прогнать» пустой токен хуже, чем упасть заметно.
     *
     * @param array<string,string> $form
     * @return array{access_token:string,refresh_token:string,expires_in:int,user_id:int}
     */
    private function request(array $form): array
    {
        if (!self::configured()) {
            throw new \RuntimeException('VK: не заданы vk_vechesk.client_id и VK_VECHESK_CLIENT_SECRET');
        }
        $raw = $this->http !== null
            ? ($this->http)(self::TOKEN_URL, $form)
            : $this->post(self::TOKEN_URL, $form);
        if ($raw === null) {
            throw new \RuntimeException('VK ID: сетевой сбой или пустой ответ');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('VK ID: не JSON: ' . mb_substr($raw, 0, 200));
        }
        if (isset($data['error'])) {
            throw new \RuntimeException(
                'VK ID: ' . (string) ($data['error'] ?? 'ошибка')
                . ' — ' . (string) ($data['error_description'] ?? '')
            );
        }
        $access = (string) ($data['access_token'] ?? '');
        $refresh = (string) ($data['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new \RuntimeException('VK ID: в ответе нет access_token/refresh_token');
        }
        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
            'user_id'       => (int) ($data['user_id'] ?? 0),
        ];
    }

    /** @param array{access_token:string,refresh_token:string,expires_in:int,user_id:int} $res */
    private function persist(array $res, string $now): void
    {
        $ttl = $res['expires_in'] > 0 ? $res['expires_in'] : 3600;
        $expiresAt = date('Y-m-d H:i:s', (int) strtotime($now) + $ttl);
        $this->tokens->save($res['access_token'], $res['refresh_token'], $expiresAt, $now);
    }

    /**
     * POST application/x-www-form-urlencoded. Ответ читаем и при HTTP 4xx:
     * тело ошибки VK информативнее кода.
     *
     * @param array<string,string> $form
     */
    private function post(string $url, array $form): ?string
    {
        $payload = http_build_query($form);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false) { error_log('VK ID: curl ошибка: ' . $err); return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 15,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
