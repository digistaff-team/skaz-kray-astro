<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, Csrf, Flash, TelegramBot, Validator, View};
use SkazResidents\Repository\BroadcastListRepository;
use SkazResidents\Repository\FamilyRepository;
use SkazResidents\Service\BotNotify;
use SkazResidents\Service\TelegramOutbox;

/**
 * Массовая рассылка жителям в Telegram (@SkazKray_bot) — только админ сайта.
 * Текст уходит как есть (без parse_mode): так спецсимволы HTML/Markdown не
 * ломают сообщение. Отправка — после ответа браузеру (BotNotify::afterResponse),
 * с паузой между сообщениями, чтобы не упереться в лимиты Bot API (~30/сек).
 *
 * Адресаты: всем с Telegram / одной семье / сохранённому списку адресатов
 * (см. BroadcastListRepository — списки составляются и правятся на этой же
 * странице и на странице редактирования списка).
 */
final class BroadcastController
{
    private const TARGET_ALL_TG = 'all_tg';   // все семьи с привязанным Telegram
    private const TARGET_ALL    = 'all';      // то же — совпадает с all_tg (лимит бота общий)
    private const TARGET_FAMILY = 'family';   // одна конкретная семья
    private const TARGET_LIST   = 'list';     // сохранённый список адресатов

    private const PATH_INDEX = '/poselenie/moderation/rassylka';

    public function __construct(
        private FamilyRepository $families = new FamilyRepository(),
        private BroadcastListRepository $lists = new BroadcastListRepository(),
        /** Чаты-группы, доступные как адресаты; null — читать из config('telegram'). */
        private ?array $groupChats = null
    ) {}

    /** Страница с формой рассылки и панелью сохранённых списков адресатов. */
    public function index(): void
    {
        Auth::requireAdmin();
        View::render('broadcast/index', [
            'targets' => $this->families->listWithTelegram(),
            'lists'   => $this->lists->allWithCounts(),
            'errors'  => [],
            'old'     => [],
        ], 'Рассылка в Telegram');
    }

    /** Отправка: валидация → Flash+редирект → отправка после ответа. */
    public function send(): void
    {
        Auth::requireAdmin();
        Csrf::guard();

        $text   = trim((string) ($_POST['text'] ?? ''));
        $target = (string) ($_POST['target'] ?? self::TARGET_ALL_TG);
        $family = (int) ($_POST['family_id'] ?? 0);
        $list   = (int) ($_POST['list_id'] ?? 0);
        if ($target === self::TARGET_LIST) { $family = 0; }   // список не зависит от выбора семьи
        if ($target !== self::TARGET_LIST) { $list = 0; }

        $errors = [];
        if ($text === '') {
            $errors['text'] = 'Текст рассылки не может быть пустым.';
        } elseif (mb_strlen($text) > 4000) {
            $errors['text'] = 'Текст длиннее 4000 символов — Telegram не примет такое сообщение.';
        }
        if (!in_array($target, [self::TARGET_ALL_TG, self::TARGET_ALL, self::TARGET_FAMILY, self::TARGET_LIST], true)) {
            $errors['target'] = 'Неизвестный получатель рассылки.';
        }
        $recipients = [];
        if (!$errors) {
            $recipients = $this->resolveRecipients($target, $family, $list);
            if ($recipients === []) {
                $errors['target'] = $target === self::TARGET_LIST
                    ? 'В выбранном списке нет адресатов с привязанным Telegram.'
                    : 'У выбранного получателя нет привязанного Telegram.';
            }
        }
        if ($errors) {
            View::render('broadcast/index', [
                'targets' => $this->families->listWithTelegram(),
                'lists'   => $this->lists->allWithCounts(),
                'errors'  => $errors,
                'old'     => ['text' => $text, 'target' => $target, 'family_id' => $family, 'list_id' => $list],
            ], 'Рассылка в Telegram');
            return;
        }

        Flash::set('success', 'Рассылка запущена: сообщения уйдут ' . count($recipients) . ' адресатам.');
        header('Location: ' . self::PATH_INDEX);
        $this->deliver($recipients, $text);
    }

    /** Создать новый список адресатов (название) — сразу на его страницу. */
    public function createList(): void
    {
        Auth::requireAdmin();
        Csrf::guard();

        $name = trim((string) ($_POST['name'] ?? ''));
        if (!Validator::length($name, 2, 120)) {
            View::render('broadcast/index', [
                'targets' => $this->families->listWithTelegram(),
                'lists'   => $this->lists->allWithCounts(),
                'errors'  => ['name' => 'Название списка: 2–120 символов.'],
                'old'     => ['text' => (string) ($_POST['text'] ?? ''), 'target' => (string) ($_POST['target'] ?? self::TARGET_ALL_TG), 'family_id' => (int) ($_POST['family_id'] ?? 0), 'list_id' => (int) ($_POST['list_id'] ?? 0), 'name' => $name],
            ], 'Рассылка в Telegram');
            return;
        }
        $id = $this->lists->create($name);
        Flash::set('success', 'Список «' . $name . '» создан — добавьте адресатов.');
        header('Location: ' . self::listPath($id));
    }

    /** Страница редактирования списка: переименование, члены, добавление/удаление. */
    public function showList(array $params): void
    {
        Auth::requireAdmin();
        $list = $this->requireList((int) ($params['id'] ?? 0));
        if ($list === null) { return; }
        View::render('broadcast/list', [
            'list'    => $list,
            'members' => $this->lists->members((int) $list['id']),
            'chats'   => $this->lists->chats((int) $list['id']),
            'addable' => $this->addableFamilies((int) $list['id']),
            'group_chats' => $this->availableGroupChats(),
            'errors'  => [],
            'old'     => [],
        ], 'Список адресатов — рассылка');
    }

    /** Переименовать список / добавить-удалить адресата / удалить список (по name=action). */
    public function updateList(array $params): void
    {
        Auth::requireAdmin();
        Csrf::guard();
        $id   = (int) ($params['id'] ?? 0);
        $list = $this->requireList($id);
        if ($list === null) { return; }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'rename') {
            $this->renameList($id, $list);
        } elseif ($action === 'delete') {
            $this->lists->delete($id);
            Flash::set('success', 'Список «' . (string) $list['name'] . '» удалён.');
            header('Location: ' . self::PATH_INDEX);
        } elseif ($action === 'add') {
            $this->addMember($id, $list);
        } elseif ($action === 'add_chat') {
            $this->addChat($id, $list);
        } elseif ($action === 'remove') {
            $this->lists->removeMember($id, (int) ($_POST['family_id'] ?? 0));
            Flash::set('success', 'Адресат убран из списка.');
            header('Location: ' . self::listPath($id));
        } elseif ($action === 'remove_chat') {
            $this->lists->removeChat($id, (int) ($_POST['chat_id'] ?? 0));
            Flash::set('success', 'Чат убран из списка.');
            header('Location: ' . self::listPath($id));
        } else {
            // Неизвестное действие — просто вернуть на страницу списка.
            header('Location: ' . self::listPath($id));
        }
    }

    private function renameList(int $id, array $list): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!Validator::length($name, 2, 120)) {
            View::render('broadcast/list', [
                'list'    => $list,
                'members' => $this->lists->members($id),
                'addable' => $this->addableFamilies($id),
                'errors'  => ['name' => 'Название списка: 2–120 символов.'],
                'old'     => ['name' => $name],
            ], 'Список адресатов — рассылка');
            return;
        }
        $this->lists->rename($id, $name);
        Flash::set('success', 'Название списка обновлено.');
        header('Location: ' . self::listPath($id));
    }

    /**
     * Добавить чат-группу в список: только из списка разрешённых (config
     * telegram.broadcast_group_chats — «Чат Действуем вместе» и др.). Чужой
     * chat_id из POST игнорируется.
     */
    private function addChat(int $id, array $list): void
    {
        $chatId = (int) ($_POST['chat_id'] ?? 0);
        $allowed = $this->availableGroupChats();
        $found   = null;
        foreach ($allowed as $c) {
            if ((int) $c['chat_id'] === $chatId) { $found = $c; break; }
        }
        if (!$found) {
            Flash::set('error', 'Чат не найден в списке разрешённых — выберите чат из списка.');
            header('Location: ' . self::listPath($id));
            return;
        }
        $this->lists->addChat($id, $chatId, (string) $found['title']);
        Flash::set('success', 'Чат «' . (string) $found['title'] . '» добавлен в список.');
        header('Location: ' . self::listPath($id));
    }

    /**
     * Чаты-группы, доступные как адресаты рассылки: инжектированный список
     * (тесты) либо config('telegram.broadcast_group_chats') в бою.
     *
     * @return array<int,array{chat_id:int,title:string}>
     */
    private function availableGroupChats(): array
    {
        if ($this->groupChats !== null) { return $this->groupChats; }
        $cfg  = Config::get('telegram');
        $rows = is_array($cfg) ? ($cfg['broadcast_group_chats'] ?? []) : [];
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['chat_id'] ?? 0);
            if ($id === 0) { continue; }
            $out[] = ['chat_id' => $id, 'title' => (string) ($row['title'] ?? '')];
        }
        return $out;
    }

    /** Добавить адресата: семья из select и/или свободный email/Telegram ID. */
    private function addMember(int $id, array $list): void
    {
        $familyId    = (int) ($_POST['family_id'] ?? 0);
        $addByFamily = $familyId > 0 ? $this->families->findById($familyId) : null;
        if (!$addByFamily) {
            $addByFamily = $this->resolveFamily(trim((string) ($_POST['who'] ?? '')));
        }
        if (!$addByFamily) {
            Flash::set('error', 'Адресат не найден: выберите поместье из списка или введите его email либо Telegram ID.');
            header('Location: ' . self::listPath($id));
            return;
        }
        $this->lists->addMember($id, (int) $addByFamily['id']);
        Flash::set('success', '«' . (string) $addByFamily['name'] . '» добавлен(а) в список.');
        header('Location: ' . self::listPath($id));
    }

    /**
     * @param int $listId id сохранённого списка адресатов (для target=list)
     * @return array<int,array<string,mixed>> адресаты рассылки
     */
    private function resolveRecipients(string $target, int $familyId, int $listId = 0): array
    {
        if ($target === self::TARGET_FAMILY) {
            $family = $familyId > 0 ? $this->families->findById($familyId) : null;
            if (!$family || $family['status'] !== 'active' || ($family['telegram_id'] ?? null) === null) {
                return [];
            }
            return [$family];
        }
        if ($target === self::TARGET_LIST) {
            if ($listId <= 0 || !$this->lists->find($listId)) { return []; }
            $onTelegram = $this->lists->recipients($listId);
            $allMembers = $this->lists->members($listId);
            $chats      = $this->lists->chats($listId);
            $familyOnly = count($onTelegram) - count($chats);
            if ($familyOnly < count($allMembers)) {
                // Члены без Telegram (не привязали бота / неактивны) — рассылка их
                // не заденет, но админ должен знать, что список сузился.
                error_log('Broadcast: список ' . $listId . ': с Telegram ' . $familyOnly
                    . ' из ' . count($allMembers) . ' адресатов — остальные пропущены');
            }
            return $onTelegram;
        }
        return $this->families->listWithTelegram();
    }

    /** Семьи, которых ещё нет в списке, — для выпадающего «Добавить». *
     * @return array<int,array<string,mixed>>
     */
    private function addableFamilies(int $listId): array
    {
        $inList = [];
        foreach ($this->lists->members($listId) as $m) {
            $inList[(int) $m['family_id']] = true;
        }
        return array_values(array_filter(
            $this->families->listWithTelegram(),
            static fn(array $f): bool => !isset($inList[(int) $f['id']])
        ));
    }

    /** Семья по свободному вводу: email, затем числовой Telegram ID. null — не найдена. */
    private function resolveFamily(string $who): ?array
    {
        if ($who === '') { return null; }
        if (Validator::email($who)) {
            $f = $this->families->findByEmail($who);
            if ($f) { return $f; }
        }
        if (preg_match('/^\d+$/', $who)) {
            $f = $this->families->findByTelegramId((int) $who);
            if ($f) { return $f; }
        }
        return null;
    }

    /** Список по id или 404-страница (null — вызывающий обязан return). */
    private function requireList(int $id): ?array
    {
        $list = $id > 0 ? $this->lists->find($id) : null;
        if (!$list) {
            http_response_code(404);
            View::render('public/notfound', [], 'Список адресатов не найден');
            return null;
        }
        return $list;
    }

    private static function listPath(int $id): string
    {
        return self::PATH_INDEX . '/spiski/' . $id;
    }

    /**
     * Отправка после ответа браузеру. Пауза 30 мс между сообщениями + жёсткий
     * лимит 2500 за запуск — страховка от долгого прогона и лимитов Bot API.
     * Ошибки отдельных адресатов (заблокировали бота) — только в error_log.
     *
     * Адресат — строка {id, name, telegram_id}: семья (id > 0) или чат-группа
     * (id = 0, telegram_id отрицательный — chat_id супергруппы). SendMessage
     * принимает chat_id строкой и чатам, и личкам — формат не различаем.
     *
     * @param array<int,array<string,mixed>> $recipients
     */
    private function deliver(array $recipients, string $text): void
    {
        $token     = (string) (Config::get('telegram')['bot_token'] ?? '');
        BotNotify::afterResponse(static function () use ($recipients, $text, $token): void {
            $sent = 0;
            foreach ($recipients as $r) {
                if ($sent >= 2500) {
                    error_log('Broadcast: достигнут лимит 2500 сообщений за один запуск');
                    break;
                }
                $chatId   = (string) ($r['telegram_id'] ?? '');
                $familyId = (int) ($r['id'] ?? 0);
                if ($chatId === '') { continue; }
                $messageId = TelegramBot::sendMessage($token, $chatId, $text);
                if ($messageId !== null) {
                    $sent++;
                    // Журнал сообщения — chat_id + message_id, чтобы потом можно
                    // было переписать/удалить рассылку (см. TelegramOutbox).
                    // У чата family_id нет — в журнал уйдёт NULL (семьи — свой id).
                    TelegramOutbox::record($chatId, $messageId, 'broadcast', 'send', $familyId ?: null, $text);
                } else {
                    error_log('Broadcast: сообщение не ушло ' . ($familyId > 0 ? 'семье ' . $familyId : 'в чат')
                        . ' (tg ' . $chatId . ')');
                }
                usleep(30_000);
            }
            error_log('Broadcast: отправлено ' . $sent . ' из ' . count($recipients) . ' адресатов');
        });
    }
}
