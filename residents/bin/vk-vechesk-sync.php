<?php
declare(strict_types=1);
/** Автономный режим: bootstrap поднимает только .env + конфиг, без сессий и БД.
 *  ROOT=1 — то же, но и без session_start() (запуск от root по ssh: у root нет
 *  прав на var/sessions, и обычный bootstrap в этом случае падает). */
define('SKAZ_CRON_NO_DB', true);
define('SKAZ_CRON_ROOT', in_array('--root', $argv ?? [], true));

/**
 * Пересылка новых постов ВК-группы «Вече» (https://vk.ru/vechesk, id 63502598)
 * в Telegram-рассылку «Участники Вече» (семьи с привязанным Telegram и подпиской
 * на группу в ВК). Тонкий cron по образцу water-level-snapshot.php.
 *
 * Запуск:
 *   php bin/vk-vechesk-sync.php            — боевой прогон
 *   php bin/vk-vechesk-sync.php --dry-run  — показать, что было бы отправлено
 *   (от root по ssh добавляется --root: без него bootstrap спотыкается о
 *    каталог сессий var/sessions, который root'у не писать)
 *
 * Состояние (id последнего пересланного поста) хранится в таблице
 * vk_vechesk_state (config/vk-vechesk-state.sql, накатывается вручную).
 * Чтение стены — user-токен VK ID (Service\VKOauth, таблица vk_oauth_token,
 * подключается на /poselenie/vk-vechesk). Без подключения прогон завершается
 * с ошибкой и ничего не отправляет (даже в dry-run). Подписчики кешируются
 * в vk_group_members_cache на сутки.
 *
 * cron (каждые 15 минут):
 *   каждые 15 мин: cd /path/residents && php bin/vk-vechesk-sync.php >> storage/logs/vk-vechesk-sync.log 2>&1
 */

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Repository\VkOauthTokenRepository;
use SkazResidents\Service\VK;
use SkazResidents\Service\VKBridge;
use SkazResidents\Service\VKOauth;
use SkazResidents\TelegramBot;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)
require __DIR__ . '/../src/bootstrap.php';  // .env и config/config.local.php

$dryRun = in_array('--dry-run', $argv, true);

// --root: запуск от root по ssh — bootstrap пропускает сессию (var/sessions
// root'у не писать). В cron скрипт идёт от www-data и без этой опции.
if (in_array('--root', $argv, true)) {
    $argv = array_values(array_filter($argv, static fn(string $a): bool => $a !== '--root'));
}

try {
    $conf = (array) (Config::get('vk_vechesk') ?? []);
    $listId = (int) ($conf['list_id'] ?? 0);
    $groupId = (int) ($conf['group_id'] ?? 0);
    if ($listId <= 0 || $groupId <= 0) {
        fwrite(STDERR, "нет config.vk_vechesk.list_id / group_id в config/config.local.php\n");
        exit(1);
    }

    $db = Database::connect(Config::get('db'));

    $tokens = new VkOauthTokenRepository();
    $vk = new VK(null, (new VKOauth(null, $tokens))->accessToken(date('Y-m-d H:i:s')));

    $bridge = new VKBridge(
        $db,
        static fn(int $gid): array => $vk->wallPosts($gid, 10),
        static function (int $gid) use ($vk, $tokens): array {
            $fetched = $tokens->membersFetchedAt($gid);
            if ($fetched !== '' && strtotime($fetched) > time() - 86400) {
                $cached = $tokens->membersCache($gid);
                if ($cached !== null) { return $cached; }
            }
            $members = $vk->groupMembers($gid);
            $tokens->saveMembers($gid, $members, date('Y-m-d H:i:s'));
            return $members;
        },
        static function (int $familyId, int $telegramId, string $text): int {
            // message_id для журнала исходящих (TelegramOutbox внутри моста).
            // Токен — вложенный ключ: Config::get('telegram.token') не работает
            // (Config не ходит по точкам), возвращает null → пустой токен →
            // sendMessage сразу отдаёт null и пост не уходит никому.
            $tg = Config::get('telegram');
            return TelegramBot::sendMessageId(
                (string) (is_array($tg) ? ($tg['bot_token'] ?? '') : ''),
                (string) $telegramId,
                $text
            ) ?? 0;
        },
        $dryRun
    );

    $stats = $bridge->sync([
        'list_id'    => $listId,
        'group_id'   => $groupId,
        'list_name'  => (string) ($conf['list_name'] ?? 'рассылка #' . $listId),
        'group_name' => (string) ($conf['group_name'] ?? 'Вече'),
    ]);

    printf(
        "[%s] vk-vechesk%s: новых постов %d, отправок %d, постов без подписчиков %d, курсор %d\n",
        date('Y-m-d H:i:s'),
        $dryRun ? ' (dry-run)' : '',
        $stats['new_posts'],
        $stats['sent'],
        $stats['no_subscribers'],
        $bridge->lastPostId()
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] vk-vechesk: ' . $e->getMessage() . "\n");
    exit(1);
}