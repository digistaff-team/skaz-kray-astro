<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, View, TelegramWebApp, TelegramSubscription};
use SkazResidents\Repository\FamilyRepository;

/**
 * Авто-логин жителя во внутренний портал через Telegram Mini App (@SkazKray_bot).
 * Порт механизма abconsult (провайдер telegram-miniapp + гейт подписки) в PHP.
 *
 * Поток: бот открывает Mini App на /poselenie/tg → страница отдаёт initData на
 * /poselenie/tg/login → сервер проверяет подпись initData и членство в группе
 * жителей (getChatMember) → апсертит аккаунт по telegram_id и логинит. Не
 * подписан или ошибка проверки → экран /poselenie/tg/gate (fail-closed).
 *
 * Deep-link на конкретную страницу портала — параметр `startapp` ссылки бота
 * t.me/SkazKray_bot/app?startapp=<base64url путь> (порт контракта из
 * abconsult-app: telegram-webapp-client.ts/startParamToPath). Значение читается
 * клиентским JS (initDataUnsafe.start_param ИЛИ query ?startapp=/?tgWebAppStartParam=,
 * т.к. Telegram не всегда кладёт его в initDataUnsafe) и шлётся вместе с initData —
 * сервер сам его не видит (Telegram передаёт данные запуска через URL-фрагмент,
 * недоступный PHP). decodeStartParam() ограничивает путь префиксом /poselenie/
 * (защита от open-redirect), иначе fallback на /poselenie/app.
 */
final class TgAuthController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /** Страница-вход Mini App: грузит Telegram SDK и отправляет initData. */
    public function entry(): void
    {
        View::render('tg/entry', ['alreadyLoggedIn' => Auth::id() !== null], 'Вход через Telegram');
    }

    /** POST /poselenie/tg/login — принимает initData, отвечает JSON. */
    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $cfg = Config::get('telegram');
        $token = (string) ($cfg['bot_token'] ?? '');
        $chatId = (string) ($cfg['group_chat_id'] ?? '');
        $redirect = self::decodeStartParam((string) ($_POST['startapp'] ?? ''));

        $initData = (string) ($_POST['initData'] ?? '');
        $user = TelegramWebApp::verify($initData, $token);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'reason' => 'invalid']);
            return;
        }

        // Гейт подписки на группу жителей (fail-closed).
        $status = TelegramSubscription::status($user['id'], $token, $chatId);
        if ($status !== 'subscribed') {
            echo json_encode(['ok' => false, 'reason' => $status]); // not_subscribed | error
            return;
        }

        $telegramId = (int) $user['id'];
        $username = ($user['username'] ?? '') !== '' ? $user['username'] : null;
        $family = $this->families->findByTelegramId($telegramId);
        if (!$family) {
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($name === '') { $name = $username !== null ? '@' . $username : 'Житель'; }
            $id = $this->families->createTelegramFamily($telegramId, mb_substr($name, 0, 160), $username);
            $family = $this->families->findById($id);
        } elseif (($family['telegram_username'] ?? null) !== $username) {
            // @username мог измениться/появиться — держим актуальным для ссылки «Tg».
            $this->families->setTelegramUsername((int) $family['id'], $username);
        }
        if (!$family || $family['status'] === 'blocked') {
            echo json_encode(['ok' => false, 'reason' => 'blocked']);
            return;
        }

        Auth::login($family);
        echo json_encode(['ok' => true, 'redirect' => $redirect]);
    }

    /** Экран «подпишитесь на группу жителей». */
    public function gate(): void
    {
        $cfg = Config::get('telegram');
        View::render('tg/gate', [
            'groupLink' => (string) ($cfg['group_link'] ?? ''),
            'reason'    => ($_GET['reason'] ?? '') === 'error' ? 'error' : 'not_subscribed',
        ], 'Доступ жителей');
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
