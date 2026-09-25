<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{Auth, CouncilAuth, Config, LoginThrottle, View, TelegramWebApp};
use SkazResidents\Repository\CouncilMemberRepository;

/**
 * Вход члена совета через Telegram Mini App (@SkazKray_bot) + привязка по коду.
 *
 * Поток:
 *  1. Член открывает ссылку из Telegram → Mini App /sovet/tg отдаёт initData на
 *     /sovet/tg/login. Сервер проверяет подпись (TelegramWebApp::verify).
 *  2. Если Telegram уже привязан к записи ростера → вход, redirect на /sovet.
 *  3. Если нет → reason:need_surname → клиент ведёт на /sovet/tg/familiya, где член
 *     вводит фамилию и одноразовый код, выданный администратором совета
 *     (/sovet/upravlenie). Одной фамилии мало: состав совета не секрет.
 *
 * Подписки на группу (в отличие от входа жителей) НЕТ — гейт это сам список совета.
 */
final class TgAuthController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    /** Страница-вход Mini App: грузит Telegram SDK и отправляет initData. */
    public function entry(): void
    {
        View::render('council/tg/entry', ['alreadyLoggedIn' => CouncilAuth::id() !== null], 'Вход в Совет', self::LAYOUT);
    }

    /** POST /sovet/tg/login — принимает initData, отвечает JSON. */
    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $user = $this->verify();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'reason' => 'invalid']);
            return;
        }

        $member = $this->members->findByTelegramId((int) $user['id']);
        if (!$member) {
            echo json_encode(['ok' => false, 'reason' => 'need_surname']);
            return;
        }
        if ($member['status'] !== 'active') {
            echo json_encode(['ok' => false, 'reason' => 'blocked']);
            return;
        }

        CouncilAuth::login($member);
        Auth::setPlatform('tg');
        echo json_encode(['ok' => true, 'redirect' => self::decodeStart((string) ($_POST['startapp'] ?? ''))]);
    }

    /** base64url(путь) из start_param → безопасный путь внутри /sovet, иначе /sovet. */
    private static function decodeStart(string $startParam, string $fallback = '/sovet'): string
    {
        if ($startParam === '') { return $fallback; }
        $b64 = strtr($startParam, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) { $b64 .= str_repeat('=', 4 - $pad); }
        $decoded = base64_decode($b64, true);
        if ($decoded === false || strpos($decoded, '/sovet') !== 0) { return $fallback; }
        return $decoded;
    }

    /** Форма ввода фамилии (внутри Mini App; initData подставляет клиентский JS). */
    public function showClaim(): void
    {
        View::render('council/tg/familiya', [
            'surname' => '',
            'error'   => null,
        ], 'Вход в Совет', self::LAYOUT);
    }

    /** POST /sovet/tg/claim — привязка Telegram к записи ростера по фамилии и коду администратора. */
    public function claim(): void
    {
        $user = $this->verify();
        if (!$user) {
            $this->renderClaim('', 'Не удалось подтвердить Telegram. Откройте раздел через бота @SkazKray_bot.');
            return;
        }
        $telegramId = (int) $user['id'];

        // Уже привязан (например, повторная отправка) — просто входим.
        $existing = $this->members->findByTelegramId($telegramId);
        if ($existing) {
            if ($existing['status'] !== 'active') {
                $this->renderClaim('', 'Ваш доступ заблокирован. Обратитесь к администратору совета.');
                return;
            }
            CouncilAuth::login($existing);
            Auth::setPlatform('tg');
            header('Location: /sovet');
            return;
        }

        $surname = trim($_POST['surname'] ?? '');
        $code    = (string) ($_POST['code'] ?? '');
        if ($surname === '' || trim($code) === '') {
            $this->renderClaim($surname, 'Введите фамилию и код привязки.');
            return;
        }

        // Лимит по Telegram-аккаунту: его id подписан Telegram, подделать нельзя.
        $throttleKey = 'council-claim:tg:' . $telegramId;
        if (LoginThrottle::exceeded($throttleKey)) {
            $this->renderClaim($surname, 'Слишком много попыток. Попробуйте позже.');
            return;
        }

        $member = $this->members->claimTelegramByCode($surname, $code, $telegramId, time());
        if (!$member) {
            LoginThrottle::record($throttleKey);
            $this->renderClaim($surname, 'Фамилия или код не подошли. Код действует 7 дней и только один раз — если он истёк, попросите новый у администратора совета.');
            return;
        }

        CouncilAuth::login($member);
        Auth::setPlatform('tg');
        header('Location: /sovet');
    }

    // --- helpers ---------------------------------------------------------------

    /** @return array{id:string,first_name:?string,last_name:?string,username:?string}|null */
    private function verify(): ?array
    {
        $token = (string) (Config::get('telegram')['bot_token'] ?? '');
        return TelegramWebApp::verify((string) ($_POST['initData'] ?? ''), $token);
    }

    private function renderClaim(string $surname, ?string $error): void
    {
        View::render('council/tg/familiya', compact('surname', 'error'), 'Вход в Совет', self::LAYOUT);
    }
}
