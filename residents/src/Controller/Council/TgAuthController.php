<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Config, View, TelegramWebApp};
use SkazResidents\Repository\CouncilMemberRepository;

/**
 * Вход члена совета через Telegram Mini App (@SkazKray_bot) + привязка по фамилии.
 *
 * Поток:
 *  1. Член открывает ссылку из Telegram → Mini App /sovet/tg отдаёт initData на
 *     /sovet/tg/login. Сервер проверяет подпись (TelegramWebApp::verify).
 *  2. Если Telegram уже привязан к записи ростера → вход, redirect на /sovet.
 *  3. Если нет → reason:need_surname → клиент ведёт на /sovet/tg/familiya, где член
 *     вводит фамилию. Фамилия сверяется со списком (только незанятые записи):
 *       0 совпадений → отказ; 1 → привязка+вход; >1 (неуникальная фамилия) → выбор по имени.
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
            'surname'    => '',
            'candidates' => [],
            'error'      => null,
        ], 'Вход в Совет', self::LAYOUT);
    }

    /** POST /sovet/tg/claim — привязка Telegram к записи ростера по фамилии. */
    public function claim(): void
    {
        $user = $this->verify();
        if (!$user) {
            $this->renderClaim('', [], 'Не удалось подтвердить Telegram. Откройте раздел через бота @SkazKray_bot.');
            return;
        }
        $telegramId = (int) $user['id'];

        // Уже привязан (например, повторная отправка) — просто входим.
        $existing = $this->members->findByTelegramId($telegramId);
        if ($existing) {
            if ($existing['status'] !== 'active') {
                $this->renderClaim('', [], 'Ваш доступ заблокирован. Обратитесь к администратору совета.');
                return;
            }
            CouncilAuth::login($existing);
            header('Location: /sovet');
            return;
        }

        $surname  = trim($_POST['surname'] ?? '');
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($surname === '') {
            $this->renderClaim('', [], 'Введите фамилию.');
            return;
        }

        $matches = $this->members->findUnclaimedBySurname($surname);

        if (!$matches) {
            $msg = $this->members->findBySurnameAny($surname)
                ? 'Эта фамилия уже привязана к другому Telegram-аккаунту. Обратитесь к администратору совета.'
                : 'Фамилия не найдена в списке Совета. Проверьте написание или обратитесь к администратору.';
            $this->renderClaim($surname, [], $msg);
            return;
        }

        // Неуникальная фамилия — нужно уточнить, кто именно.
        if (count($matches) > 1) {
            $chosen = null;
            foreach ($matches as $m) {
                if ((int) $m['id'] === $memberId) { $chosen = $m; break; }
            }
            if (!$chosen) {
                $candidates = array_map(static fn ($m) => ['id' => (int) $m['id'], 'name' => (string) $m['name']], $matches);
                $this->renderClaim($surname, $candidates, $memberId > 0 ? 'Выберите себя из списка.' : null);
                return;
            }
            $matches = [$chosen];
        }

        $member = $matches[0];
        $this->members->bindTelegram((int) $member['id'], $telegramId);
        $member['telegram_id'] = $telegramId;
        CouncilAuth::login($member);
        header('Location: /sovet');
    }

    // --- helpers ---------------------------------------------------------------

    /** @return array{id:string,first_name:?string,last_name:?string,username:?string}|null */
    private function verify(): ?array
    {
        $token = (string) (Config::get('telegram')['bot_token'] ?? '');
        return TelegramWebApp::verify((string) ($_POST['initData'] ?? ''), $token);
    }

    /** @param array<int,array{id:int,name:string}> $candidates */
    private function renderClaim(string $surname, array $candidates, ?string $error): void
    {
        View::render('council/tg/familiya', compact('surname', 'candidates', 'error'), 'Вход в Совет', self::LAYOUT);
    }
}
