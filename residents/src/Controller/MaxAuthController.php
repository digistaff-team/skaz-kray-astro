<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, View, MaxWebApp, MaxSubscription};
use SkazResidents\Repository\FamilyRepository;

/**
 * Авто-логин жителя во внутренний портал через мини-приложение MAX. Зеркало
 * {@see TgAuthController} для платформы MAX: тот же поток, но подпись/секрет/гейт
 * и идентификатор пользователя — MAX.
 *
 * URL мини-приложения в MAX — https://skaz-kray.ru/max (единственное мини-
 * приложение бота в MAX открывает раздел жителей).
 *
 * Поток: бот открывает мини-приложение на /max → страница отдаёт initData на
 * /max/login → сервер проверяет подпись (MaxWebApp) и членство в группе жителей
 * MAX (MaxSubscription) → апсертит аккаунт по max_user_id и логинит. Не в группе
 * или ошибка → /max/gate (fail-closed). Deep-link — параметр startapp (base64url
 * путь внутри /poselenie/).
 */
final class MaxAuthController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /** Страница-вход мини-приложения: грузит MAX Bridge и отправляет initData. */
    public function entry(): void
    {
        View::render('max/entry', ['alreadyLoggedIn' => Auth::id() !== null], 'Вход через MAX');
    }

    /** POST /poselenie/max/login — принимает initData, отвечает JSON. */
    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        [$token, $chatId] = self::maxCreds();
        $redirect = self::decodeStartParam((string) ($_POST['startapp'] ?? ''));

        $initData = (string) ($_POST['initData'] ?? '');
        $user = MaxWebApp::verify($initData, $token);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'reason' => 'invalid']);
            return;
        }

        // Гейт членства в группе жителей MAX (fail-closed).
        $status = MaxSubscription::status($user['id'], $token, $chatId);
        if ($status !== 'subscribed') {
            echo json_encode(['ok' => false, 'reason' => $status]); // not_subscribed | error
            return;
        }

        $maxUserId = (int) $user['id'];
        $family = $this->families->findByMaxId($maxUserId);
        if (!$family) {
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($name === '') { $name = ($user['username'] ?? '') !== '' ? '@' . $user['username'] : 'Житель'; }
            $id = $this->families->createMaxFamily($maxUserId, mb_substr($name, 0, 160));
            $family = $this->families->findById($id);
        }
        if (!$family || $family['status'] === 'blocked') {
            echo json_encode(['ok' => false, 'reason' => 'blocked']);
            return;
        }

        Auth::login($family);
        echo json_encode(['ok' => true, 'redirect' => $redirect]);
    }

    /** Экран «вступите в группу жителей в MAX». */
    public function gate(): void
    {
        $max = Config::get('max');
        View::render('max/gate', [
            'groupLink' => is_array($max) ? (string) ($max['group_link'] ?? '') : '',
            'reason'    => ($_GET['reason'] ?? '') === 'error' ? 'error' : 'not_subscribed',
        ], 'Доступ жителей');
    }

    /** Токен бота и chat_id группы жителей MAX (config 'max' с фолбэком на env). */
    private static function maxCreds(): array
    {
        $max    = Config::get('max');
        $token  = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        if ($token === '') { $token = (string) (getenv('SKAZKRAY_MAX_BOT_TOKEN') ?: ''); }
        $chatId = is_array($max) ? (string) ($max['group_chat_id'] ?? '') : '';
        if ($chatId === '') { $chatId = (string) (getenv('SKAZKRAY_MAX_GROUP_CHAT_ID') ?: ''); }
        return [$token, $chatId];
    }

    /** base64url(путь) → безопасный внутренний путь портала, иначе fallback. */
    private static function decodeStartParam(string $startParam, string $fallback = '/poselenie/app'): string
    {
        if ($startParam === '') { return $fallback; }
        $b64 = strtr($startParam, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) { $b64 .= str_repeat('=', 4 - $pad); }
        $decoded = base64_decode($b64, true);
        if ($decoded === false || strpos($decoded, '/poselenie/') !== 0) { return $fallback; }
        return $decoded;
    }
}
