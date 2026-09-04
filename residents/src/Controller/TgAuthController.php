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
 */
final class TgAuthController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /** Страница-вход Mini App: грузит Telegram SDK и отправляет initData. */
    public function entry(): void
    {
        if (Auth::id() !== null) { header('Location: /poselenie/app'); return; }
        View::render('tg/entry', [], 'Вход через Telegram');
    }

    /** POST /poselenie/tg/login — принимает initData, отвечает JSON. */
    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $cfg = Config::get('telegram');
        $token = (string) ($cfg['bot_token'] ?? '');
        $chatId = (string) ($cfg['group_chat_id'] ?? '');

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
        $family = $this->families->findByTelegramId($telegramId);
        if (!$family) {
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($name === '') { $name = $user['username'] !== null ? '@' . $user['username'] : 'Житель'; }
            $id = $this->families->createTelegramFamily($telegramId, mb_substr($name, 0, 160));
            $family = $this->families->findById($id);
        }
        if (!$family || $family['status'] === 'blocked') {
            echo json_encode(['ok' => false, 'reason' => 'blocked']);
            return;
        }

        Auth::login($family);
        echo json_encode(['ok' => true, 'redirect' => '/poselenie/app']);
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
}
