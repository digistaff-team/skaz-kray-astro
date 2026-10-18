<?php
declare(strict_types=1);
/** Автономный режим: bootstrap поднимает только .env + конфиг, без сессий и БД. */
define('SKAZ_CRON_NO_DB', true);

/**
 * Пересылка новых постов ВК-группы «Вече» (https://vk.ru/vechesk, id 63502598)
 * в Telegram-рассылку «Участники Вече» (семьи с привязанным Telegram и подпиской
 * на группу в ВК). Тонкий cron по образцу water-level-snapshot.php.
 *
 * Запуск:
 *   php bin/vk-vechesk-sync.php            — боевой прогон
 *   php bin/vk-vechesk-sync.php --dry-run  — показать, что было бы отправлено
 *
 * Состояние (id последнего пересланного поста) хранится в таблице
 * vk_vechesk_state (config/vk-vechesk-state.sql, накатывается вручную).
 * Токен VK API — из config/.env: VECHESK_GROUP_VK_TOKEN (фолбэк —
 * SKAZKRAY_VK_TOKEN). Без токена прогон
 * завершается с ошибкой и ничего не отправляет (даже в dry-run).
 *
 * cron (каждые 15 минут):
 *   каждые 15 мин: cd /path/residents && php bin/vk-vechesk-sync.php >> storage/logs/vk-vechesk-sync.log 2>&1
 */

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Service\VK;
use SkazResidents\Service\VKBridge;
use SkazResidents\TelegramBot;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)
require __DIR__ . '/../src/bootstrap.php';  // .env и config/config.local.php

$dryRun = in_array('--dry-run', $argv, true);

try {
    $conf = (array) (Config::get('vk_vechesk') ?? []);
    $listId = (int) ($conf['list_id'] ?? 0);
    $groupId = (int) ($conf['group_id'] ?? 0);
    if ($listId <= 0 || $groupId <= 0) {
        fwrite(STDERR, "нет config.vk_vechesk.list_id / group_id в config/config.local.php\n");
        exit(1);
    }

    if (VK::token() === '') {
        fwrite(STDERR, 'нет VECHESK_GROUP_VK_TOKEN в config/.env — нечего запрашивать у ВК'
            . ($dryRun ? ' (даже для dry-run)' : '') . "\n");
        exit(1);
    }

    $db = Database::connect(Config::database());

    $bridge = new VKBridge(
        $db,
        static fn(int $gid): array => (new VK())->wallPosts($gid, 10),
        static fn(int $gid): array => (new VK())->groupMembers($gid),
        static function (int $familyId, int $telegramId, string $text): int {
            // message_id для журнала исходящих (TelegramOutbox внутри моста)
            return TelegramBot::sendMessageId(
                (string) Config::get('telegram.token'),
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