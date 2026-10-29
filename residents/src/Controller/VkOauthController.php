<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, Csrf, Flash, View};
use SkazResidents\Repository\VkOauthTokenRepository;
use SkazResidents\Service\VKOauth;

/**
 * Подключение доступа к стене ВК-группы «Вече» (Authorization Code Flow + PKCE).
 *
 * Стена ВК читается только user-токеном: групповой ключ VK к методам стены не
 * допускает. Пользователь подтверждает доступ один раз (эта страница), дальше
 * токен обновляется автоматически по refresh_token — см. Service\VKOauth.
 *
 * Только админ: это настройка интеграции, а не жительская функция.
 */
final class VkOauthController
{
    private const PATH = '/poselenie/vk-vechesk';

    private VKOauth $oauth;
    private VkOauthTokenRepository $tokens;

    public function __construct(?VKOauth $oauth = null, ?VkOauthTokenRepository $tokens = null)
    {
        $this->oauth  = $oauth ?? new VKOauth();
        $this->tokens = $tokens ?? new VkOauthTokenRepository();
    }

    /** Страница состояния: подключено ли, когда истекает, кнопка «Подключить». */
    public function index(): void
    {
        Auth::requireAdmin();
        $pkce  = VKOauth::pkce();
        // verifier переживает редирект на VK и обратно в той же сессии.
        $_SESSION['vk_oauth_verifier'] = $pkce['verifier'];
        $_SESSION['vk_oauth_state']    = bin2hex(random_bytes(16));

        $conf     = Config::get('vk_vechesk');
        $groupId  = is_array($conf) ? (int) ($conf['group_id'] ?? 0) : 0;

        View::render('vk/oauth', [
            'configured'  => VKOauth::configured(),
            'authUrl'     => VKOauth::configured()
                ? $this->oauth->authorizationUrl((string) $_SESSION['vk_oauth_state'], $pkce['challenge'])
                : '',
            'expiresAt'   => $this->oauth->expiresAt(),
            'lastError'   => (string) (($this->tokens->tokens() ?? [])['last_error'] ?? ''),
            'fetchedAt'   => $groupId > 0 ? $this->tokens->membersFetchedAt($groupId) : '',
            'clientId'    => VKOauth::clientId(),
            'redirectUri' => VKOauth::redirectUri(),
            'backFallback' => '/poselenie/moderation/rassylka',
        ], 'Доступ к стене ВК «Вече»');
    }

    /**
     * Callback VK: сверяет state, меняет code на токены и возвращает на страницу
     * состояния. Код живёт час — ошибку показываем сразу, а не в логах.
     */
    public function callback(): void
    {
        Auth::requireAdmin();

        $state = (string) ($_GET['state'] ?? '');
        $code  = (string) ($_GET['code'] ?? '');
        $error = (string) ($_GET['error'] ?? '');

        if ($error !== '') {
            Flash::set('error', 'VK не выдал доступ: ' . $error
                . (isset($_GET['error_description']) ? ' — ' . (string) $_GET['error_description'] : ''));
            header('Location: ' . self::PATH);
            return;
        }

        $expected = (string) ($_SESSION['vk_oauth_state'] ?? '');
        $verifier = (string) ($_SESSION['vk_oauth_verifier'] ?? '');
        unset($_SESSION['vk_oauth_state'], $_SESSION['vk_oauth_verifier']);

        if ($expected === '' || !hash_equals($expected, $state)) {
            Flash::set('error', 'Ссылка авторизации устарела — начните подключение заново.');
            header('Location: ' . self::PATH);
            return;
        }
        if ($code === '' || $verifier === '') {
            Flash::set('error', 'VK не вернул код подтверждения — начните подключение заново.');
            header('Location: ' . self::PATH);
            return;
        }

        try {
            $this->oauth->exchange($code, $verifier, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            error_log('VkOauth callback: ' . $e->getMessage());
            Flash::set('error', 'Не удалось получить доступ: ' . $e->getMessage());
            header('Location: ' . self::PATH);
            return;
        }

        Flash::set('success', 'Доступ к стене ВК «Вече» подключён — токен будет обновляться сам.');
        header('Location: ' . self::PATH);
    }

    /** Сбросить сохранённые токены (например, чтобы подключить другой аккаунт). */
    public function disconnect(): void
    {
        Auth::requireAdmin();
        Csrf::guard();
        $this->tokens->clear();
        Flash::set('success', 'Сохранённый доступ удалён. Подключите заново, если нужно.');
        header('Location: ' . self::PATH);
    }
}
