<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Database;
use PDO;

/**
 * Пересылка новых постов ВК-группы «Вече» (https://vk.ru/vechesk) в Telegram-
 * рассылку «Участники Вече» (bin/vk-vechesk-sync.php из cron).
 *
 * Кому: семь из списка «Участники Вече» с активным telegram_id, к поместью
 * которых (households.family_id) привязан хотя бы один житель с заполненным
 * residents.vk, и этот аккаунт входит в подписчики ВК-группы. То есть тем,
 * кто реально подписан на группу в ВК. Сопоставление «житель ↔ подписчик» —
 * по экранным именам (screen_name) и числовым id в одном нормализованном виде.
 *
 * Плюс чаты-группы, состоящие в том же списке (broadcast_list_members.chat_id):
 * им пост уходит всегда, без проверки подписки — у чата нет vk-аккаунта,
 * сопоставлять не с чем, а сам чат в список добавляет админ осознанно.
 * Плюс чаты-группы того же списка (broadcast_list_members.chat_id): им пост
 * уходит всегда, без проверки подписки — у чата личной ВК-аккаунта нет.
 *
 * Как: пост → короткое сообщение с ссылкой (без вложений) → TelegramBot::sendMessage
 * синхронно + запись в TelegramOutbox (журнал исходящих). После доставки id поста
 * фиксируется в vk_vechesk_state — следующий прогон берёт только более новые.
 *
 * Состояние читается/пишется самим мостом (таблица vk_vechesk_state), чтобы тесты
 * работали на SQLite без миграций. Сетевые зависимости — колбэки в конструкторе
 * (по образцу WaterAlert): в бою подставляются VK и TelegramBot.
 */
final class VKBridge
{
    public const CONTEXT = 'vk_vechesk';

    /** @var PDO */
    private $db;
    /** @var callable(int):array  id => пост с ключами id, date, text */
    private $fetchPosts;
    /** @var callable(int):array  id => подписчик (id, screen_name) */
    private $fetchMembers;
    /** @var callable(int,int,string):int  (familyId, telegramId, text) → message_id (0 — не ушло) */
    private $sendMessage;
    /** @var bool dry-run: ничего не отправлять и не записывать */
    private $dryRun;

    /**
     * @param callable(int):array  $fetchPosts   свежие посты стены (VK::wallPosts)
     * @param callable(int):array  $fetchMembers подписчики (VK::groupMembers)
     * @param callable(int,int,string):int $sendMessage отправка (TelegramBot::sendMessage, message_id)
     */
    public function __construct(
        PDO $db,
        callable $fetchPosts,
        callable $fetchMembers,
        callable $sendMessage,
        bool $dryRun = false
    ) {
        $this->db = $db;
        $this->fetchPosts = $fetchPosts;
        $this->fetchMembers = $fetchMembers;
        $this->sendMessage = $sendMessage;
        $this->dryRun = $dryRun;
    }
/**
     * Полный прогон. Пустой last_post_id (первый запуск/сброс) — ничего не
     * рассылаем, только запоминаем самый новый пост.
     *
     * @param array{list_id:int,group_id:int,list_name?:string,group_name?:string} $conf
     * @return array{new_posts:int,sent:int,no_subscribers:int} для лога cron
     */
    public function sync(array $conf): array
    {
        $listId = (int) ($conf['list_id'] ?? 0);
        $groupId = (int) ($conf['group_id'] ?? 0);
        if ($listId <= 0 || $groupId <= 0) {
            throw new \RuntimeException('vk_vechesk: в конфиге не заданы list_id и group_id');
        }

        $lastId = $this->lastPostId();
        $posts = ($this->fetchPosts)($groupId);
        $stats = ['new_posts' => 0, 'sent' => 0, 'no_subscribers' => 0];
        if ($posts === []) { return $stats; }

        $new = array_values(array_filter(
            $posts,
            static fn(array $p): bool => (int) ($p['id'] ?? 0) > $lastId
        ));
        $stats['new_posts'] = count($new);
        if ($new === []) { return $stats; }

        if ($lastId === 0) {
            // Первый запуск: старые посты не рассылаем — только запоминаем последний.
            $newest = (int) end($new)['id'];
            if (!$this->dryRun) { $this->saveLastPostId($newest); }
            return $stats;
        }

        // ВК: нормализованные ключи всех подписчиков (screen_name и idXXX).
        $subKeys = [];
        foreach (($this->fetchMembers)($groupId) as $m) {
            $sn = trim((string) ($m['screen_name'] ?? ''));
            if ($sn !== '') { $subKeys[mb_strtolower($sn)] = true; }
            if (isset($m['id'])) { $subKeys['id' . (int) $m['id']] = true; }
        }

        // Кому: семьи списка → telegram_id → резиденты с residents.vk;
        // плюс чаты-группы того же списка (им пост уходит всегда).
        $rows  = $this->candidates($listId);
        $chats = $this->chatRecipients($listId);
        $listName = (string) ($conf['list_name'] ?? 'рассылка #' . $listId);
        $groupName = (string) ($conf['group_name'] ?? 'Вече');

        foreach ($new as $post) {
            $postId = (int) ($post['id'] ?? 0);
            $text = $this->postText($post, $groupName);
            $sent = 0;
            $active = 0;

            foreach ($rows as $r) {
                $accounts = array_filter(array_map('trim', explode(',', (string) $r['vk'])));
                if ($accounts === []) { continue; }
                $active++;
                if (!$this->matches($accounts, $subKeys)) { continue; }
                $tg = (int) $r['telegram_id'];

                if ($this->dryRun) {
                    $sent++;
                    echo 'DRY-RUN [' . $listName . '] пост #' . $postId
                        . ' → tg ' . $tg . ' (' . $r['family_id'] . ")\n";
                    continue;
                }
                try {
                    $messageId = (int) ($this->sendMessage)((int) $r['family_id'], $tg, $text);
                } catch (\Throwable $e) {
                    error_log('VK->TG (family ' . $r['family_id'] . '): ' . $e->getMessage());
                    $messageId = 0;
                }
                if ($messageId > 0) {
                    $sent++;
                    self::recordOutbox($tg, $messageId, $text, (int) $r['family_id']);
                }
            }

            // Чаты — тем же колбэком отправки, но family_id = 0 (у чата его нет)
            // и без сверки с подписчиками ВК.
            foreach ($chats as $c) {
                $chatId = (int) $c['telegram_id'];
                if ($this->dryRun) {
                    $sent++;
                    echo 'DRY-RUN [' . $listName . '] пост #' . $postId
                        . ' → чат ' . $chatId . ' (' . $c['name'] . ")\n";
                    continue;
                }
                try {
                    $messageId = (int) ($this->sendMessage)(0, $chatId, $text);
                } catch (\Throwable $e) {
                    error_log('VK->TG (chat ' . $chatId . '): ' . $e->getMessage());
                    $messageId = 0;
                }
                if ($messageId > 0) {
                    $sent++;
                    self::recordOutbox($chatId, $messageId, $text, null);
                }
            }

            // «Пост без подписчиков» — только когда реально некому было уходить:
            // хоть одна семья-кандидат или хоть один чат в списке.
            if ($chats === [] && $active > 0 && $sent === 0) { $stats['no_subscribers']++; }
            $stats['sent'] += $sent;

            if (!$this->dryRun) {
                // Доставили (или некому — тоже не повторяем) — двигаем курсор.
                $this->saveLastPostId($postId);
            }
        }

        return $stats;
    }

    /** id последнего пересланного поста; 0 — ещё не пересылали. */
    public function lastPostId(): int
    {
        $row = $this->db->query('SELECT last_post_id FROM vk_vechesk_state WHERE id = 1')->fetch();
        return $row ? (int) $row['last_post_id'] : 0;
    }

    // ---- internals -------------------------------------------------------

    /**
     * Семьи-получатели (список → активные с telegram_id) с vk-аккаунтами жителей
     * через запятую. Строки-чаты (chat_id NOT NULL) здесь отсекаются: они
     * адресаты отдельного блока — см. chatRecipients().
     */
    private function candidates(int $listId): array
    {
        $st = $this->db->prepare(
            "SELECT m.family_id, f.telegram_id,
                    (SELECT GROUP_CONCAT(r.vk)
                       FROM households h
                       JOIN residents r ON r.household_id = h.id
                      WHERE h.family_id = m.family_id
                        AND r.vk IS NOT NULL AND TRIM(r.vk) <> '') AS vk
               FROM broadcast_list_members m
               JOIN families f ON f.id = m.family_id
              WHERE m.list_id = ?
                AND m.chat_id IS NULL
                AND f.status = 'active'
                AND f.telegram_id IS NOT NULL AND f.telegram_id > 0"
        );
        $st->execute([$listId]);
        return $st->fetchAll() ?: [];
    }

    /**
     * Чаты-группы того же списка — вторым адресатом ВК-пересылки. Пост уходит
     * им всегда, без сверки с подписчиками: сопоставлять vk-аккаунт чата не с
     * чем, а добавляет чат в список админ осознанно.
     *
     * @return array<int,array{telegram_id:int,name:string}>
     */
    private function chatRecipients(int $listId): array
    {
        $st = $this->db->prepare(
            'SELECT chat_id AS telegram_id, chat_title AS name
             FROM broadcast_list_members
             WHERE list_id = ? AND chat_id IS NOT NULL
             ORDER BY chat_title'
        );
        $st->execute([$listId]);
        return $st->fetchAll() ?: [];
    }

    /** Есть ли среди подписчиков группы хотя бы один vk-аккаунт семьи. */
    private function matches(array $accounts, array $subKeys): bool
    {
        foreach ($accounts as $a) {
            foreach (self::vkKeys($a) as $k) {
                if (isset($subKeys[$k])) { return true; }
            }
        }
        return false;
    }

    /**
     * Нормализация residents.vk в ключи для сверки с подписчиками: лишние
     * пробелы/хвосты URL отбрасываем, приводим к нижнему регистру. Примеры:
     * "vk.com/ivan" → ["ivan"]; "https://vk.ru/ivan/" → ["ivan"];
     * "id123" → ["id123"]; "12345" → ["12345", "id12345"].
     *
     * @return array<int,string>
     */
    public static function vkKeys(string $v): array
    {
        $v = mb_strtolower(trim($v));
        $v = preg_replace('~^https?://~', '', $v) ?? $v;
        $v = preg_replace('~^(www\.|m\.)~', '', $v) ?? $v;
        $v = preg_replace('~^(vk\.com|vk\.ru)/~', '', $v) ?? $v;
        $v = trim($v, "/ \t\n\r\0\x0B");
        if ($v === '') { return []; }

        $keys = [$v];
        $path = trim((string) (parse_url('https://' . $v, PHP_URL_PATH) ?: ''), '/');
        if ($path !== '') { $keys[] = $path; }
        foreach ($keys as $k) {
            if (preg_match('~^\d+$~', $k)) { $keys[] = 'id' . $k; }
        }
        return array_values(array_unique($keys));
    }

    /** Текст сообщения: без вложений, только конспект + ссылка. */
    private function postText(array $post, string $groupName): string
    {
        $postId = (int) ($post['id'] ?? 0);
        $url = 'https://vk.ru/wall-63502598_' . $postId;
        $text = trim((string) ($post['text'] ?? ''));
        $ts = (int) ($post['date'] ?? 0);
        $when = $ts > 0 ? date('d.m.Y', $ts) : '';

        $out = 'Новый пост в ВК-группе «' . $groupName . '»'
             . ($when !== '' ? ' от ' . $when : '') . ":\n\n";
        if ($text !== '') {
            $out .= mb_strlen($text) > 700
                ? mb_substr($text, 0, 700) . '…' . "\n\n"
                : $text . "\n\n";
        }
        return $out . $url;
    }

    /** Фиксация курсора состояния (таблица vk_vechesk_state, строка id=1). */
    private function saveLastPostId(int $postId): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare(
            'INSERT INTO vk_vechesk_state (id, last_post_id, updated_at) VALUES (1, ?, ?)
             ON CONFLICT(id) DO UPDATE SET last_post_id = excluded.last_post_id,
                                           updated_at = excluded.updated_at'
        );
        $st->execute([$postId, $now]);
    }

    /**
     * Журнал исходящих: запись в TelegramOutbox — chat_id + message_id, чтобы
     * пост появился в «Исходящих» рассылки (как при обычной рассылке).
     * У чата семьи нет — $familyId null (в MySQL колонка NULL-able).
     * TelegramOutbox пишет через Database::pdo(), а cron соединения в Database
     * не ставит (вызывался Database::connect) — поднимаем его сами.
     */
    private static function recordOutbox(int $telegramId, int $messageId, string $text, ?int $familyId): void
    {
        if ($messageId <= 0) { return; }
        try {
            Database::set(Database::pdo());
        } catch (\Throwable) {
            return;   // журнал не критичен, сообщение уже ушло
        }
        TelegramOutbox::record((string) $telegramId, $messageId, self::CONTEXT, 'send', $familyId, $text);
    }
}
