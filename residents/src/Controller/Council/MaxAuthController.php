<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Config, View, MaxWebApp};
use SkazResidents\Repository\CouncilMemberRepository;

/**
 * Вход члена совета через мини-приложение MAX (@SkazKray_bot в MAX) + привязка
 * по фамилии. Полный аналог {@see TgAuthController} для платформы MAX: разные
 * initData/секрет/идентификатор пользователя, общая модель ростера.
 *
 * Поток:
 *  1. Член открывает мини-приложение → /max отдаёт initData (window.WebApp) на
 *     /max/login. Сервер проверяет подпись (MaxWebApp::verify).
 *  2. Если MAX уже привязан к записи ростера → вход, redirect на /sovet.
 *  3. Если нет → reason:need_surname → клиент ведёт на /max/familiya, где член
 *     вводит фамилию. Сверка со списком (только записи без привязки MAX):
 *       0 → отказ; 1 → привязка+вход; >1 (неуникальная фамилия) → выбор по имени.
 *
 * Вход/сессия общие с /sovet (CouncilAuth): после логина ведём в /sovet.
 */
final class MaxAuthController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    /** Страница-вход мини-приложения: грузит MAX Bridge и отправляет initData. */
    public function entry(): void
    {
        View::render('council/max/entry', ['alreadyLoggedIn' => CouncilAuth::id() !== null], 'Вход в Совет', self::LAYOUT);
    }

    /** POST /max/login — принимает initData, отвечает JSON. */
    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $user = $this->verify();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'reason' => 'invalid']);
            return;
        }

        $member = $this->members->findByMaxId((int) $user['id']);
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

    /** Форма ввода фамилии (внутри мини-приложения; initData подставляет клиентский JS). */
    public function showClaim(): void
    {
        View::render('council/max/familiya', [
            'surname'    => '',
            'candidates' => [],
            'error'      => null,
        ], 'Вход в Совет', self::LAYOUT);
    }

    /** POST /max/claim — привязка MAX к записи ростера по фамилии. */
    public function claim(): void
    {
        $user = $this->verify();
        if (!$user) {
            $this->renderClaim('', [], 'Не удалось подтвердить MAX. Откройте раздел через мини-приложение бота @SkazKray_bot в MAX.');
            return;
        }
        $maxId = (int) $user['id'];

        // Уже привязан (например, повторная отправка) — просто входим.
        $existing = $this->members->findByMaxId($maxId);
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

        $matches = $this->members->findUnclaimedBySurnameForMax($surname);

        if (!$matches) {
            $msg = $this->members->findBySurnameAny($surname)
                ? 'Эта фамилия уже привязана к другому аккаунту MAX. Обратитесь к администратору совета.'
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
        $this->members->bindMax((int) $member['id'], $maxId);
        $member['max_user_id'] = $maxId;
        CouncilAuth::login($member);
        header('Location: /sovet');
    }

    // --- helpers ---------------------------------------------------------------

    /** @return array{id:string,first_name:?string,last_name:?string,username:?string}|null */
    private function verify(): ?array
    {
        // Токен из config.php ('max'), а если блок ещё не добавлен в боевой
        // config.php — напрямую из окружения (config/.env: SKAZKRAY_MAX_BOT_TOKEN).
        $max = Config::get('max');
        $token = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        if ($token === '') { $token = (string) (getenv('SKAZKRAY_MAX_BOT_TOKEN') ?: ''); }
        return MaxWebApp::verify((string) ($_POST['initData'] ?? ''), $token);
    }

    /** @param array<int,array{id:int,name:string}> $candidates */
    private function renderClaim(string $surname, array $candidates, ?string $error): void
    {
        View::render('council/max/familiya', compact('surname', 'candidates', 'error'), 'Вход в Совет', self::LAYOUT);
    }
}
