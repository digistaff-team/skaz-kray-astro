<?php
declare(strict_types=1);

/**
 * Рассылка уведомления о встрече Попечительского совета членам совета через
 * @SkazKray_bot (в Telegram и в MAX). Запускается cron'ом ежедневно в 06:00 UTC
 * (= 09:00 МСК).
 *
 * Логика: если дата встречи (council_meeting.starts_at) == сегодня (МСК) и за
 * этот день ещё не рассылали (notified_for) — шлём всем членам совета с
 * привязанным Telegram (через Telegram Bot API) и/или MAX (через Bot API MAX):
 * дата/время, место, дежурные, ссылка на повестку в приложении (диплинк — свой
 * для каждой платформы). Идемпотентно (notified_for = дата встречи).
 *
 * Флаги: --dry-run (показать, не отправлять), --force (игнорировать проверку
 * даты/повтора — для ручного теста).
 *
 * Запуск: php8.3 bin/council-meeting-notify.php [--dry-run] [--force]
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

use SkazResidents\{Config, Database, TelegramBot, MaxBot, Env};

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

// Секреты (SKAZKRAY_BOT_TOKEN) — из config/.env, как в bootstrap.php веб-части.
// В CLI bootstrap не подключается, поэтому грузим .env здесь явно.
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));
$pdo = Database::pdo();

$botToken = (string) (Config::get('telegram')['bot_token'] ?? '');
$appLink  = (string) (Config::get('council_app_link', 'https://t.me/SkazKray_bot/sovet') ?: 'https://t.me/SkazKray_bot/sovet');

// MAX: токен и диплинк. Токен — из config.php ('max'), а если блок ещё не заведён
// в боевом config.php — напрямую из окружения (config/.env: SKAZKRAY_MAX_BOT_TOKEN).
$maxCfg     = Config::get('max');
$maxToken   = is_array($maxCfg) ? (string) ($maxCfg['bot_token'] ?? '') : '';
if ($maxToken === '') { $maxToken = (string) (getenv('SKAZKRAY_MAX_BOT_TOKEN') ?: ''); }
$maxAppLink = is_array($maxCfg) ? (string) ($maxCfg['app_link'] ?? '') : '';
if ($maxAppLink === '') { $maxAppLink = 'https://max.ru/id643900558807_2_bot?startapp'; }

$log = static function (string $m): void { echo '[' . gmdate('Y-m-d H:i:s') . " UTC] {$m}\n"; };

// Ежедневно синхронизируем дежурного председателя по графику ротации —
// чтобы карточка встречи и это уведомление называли актуального дежурного.
$dutyByRotation = \SkazResidents\CouncilDutyRotation::apply();
if ($dutyByRotation !== null) { $log("ротация: дежурный по графику — {$dutyByRotation}"); }

$meeting = $pdo->query('SELECT * FROM council_meeting WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
if (!$meeting) { $log('council_meeting пуст — нечего рассылать'); exit(0); }

$todayMsk    = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
$meetingDay  = $meeting['starts_at'] ? substr((string) $meeting['starts_at'], 0, 10) : '';

if (!$force) {
    if ($meetingDay === '' || $meetingDay !== $todayMsk) {
        $log("сегодня ({$todayMsk}) не день встречи (встреча: " . ($meetingDay ?: 'не задана') . ') — выход');
        exit(0);
    }
    if ((string) ($meeting['notified_for'] ?? '') === $todayMsk) {
        $log("за {$todayMsk} уже рассылали — выход");
        exit(0);
    }
}

// Текст уведомления. Ссылка на приложение своя для каждой платформы (Telegram
// Mini App / мини-приложение MAX), остальное совпадает.
$makeText = static function (string $appLink) use ($meeting): string {
    return implode("\n", [
        '🗓 Сегодня встреча Попечительского совета',
        '',
        (string) $meeting['meeting_date'],
        (string) $meeting['place'],
        '',
        'Дежурный председатель: ' . (string) $meeting['duty_chair'],
        'Дежурный секретарь: ' . (string) $meeting['duty_secretary'],
        '',
        'Повестка встречи в приложении:',
        $appLink,
    ]);
};
$textTg  = $makeText($appLink);
$textMax = $makeText($maxAppLink);

// Получатели: активные члены совета с привязанным Telegram и/или MAX. Каналы
// независимы — привязавший обе платформы получит уведомление в обеих.
$tgRecipients = $pdo->query(
    "SELECT id, name, telegram_id FROM council_members
     WHERE telegram_id IS NOT NULL AND status = 'active' ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC);
$maxRecipients = $pdo->query(
    "SELECT id, name, max_user_id FROM council_members
     WHERE max_user_id IS NOT NULL AND status = 'active' ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC);

$log(($dryRun ? '[DRY-RUN] ' : '') . 'встреча ' . $meetingDay
    . ', получателей: Telegram ' . count($tgRecipients) . ', MAX ' . count($maxRecipients));

if ($dryRun) {
    echo "---- текст (Telegram) ----\n{$textTg}\n---------------\n";
    foreach ($tgRecipients as $r) { echo "  → {$r['name']} (tg {$r['telegram_id']})\n"; }
    echo "---- текст (MAX) ----\n{$textMax}\n---------------\n";
    foreach ($maxRecipients as $r) { echo "  → {$r['name']} (max {$r['max_user_id']})\n"; }
    exit(0);
}

$sent = 0; $failed = 0;
foreach ($tgRecipients as $r) {
    if (TelegramBot::sendMessage($botToken, (string) $r['telegram_id'], $textTg)) {
        $sent++;
    } else {
        $failed++;
        $log("Telegram не доставлено: {$r['name']} (tg {$r['telegram_id']})");
    }
}
foreach ($maxRecipients as $r) {
    if (MaxBot::sendMessage($maxToken, (string) $r['max_user_id'], $textMax)) {
        $sent++;
    } else {
        $failed++;
        $log("MAX не доставлено: {$r['name']} (max {$r['max_user_id']})");
    }
}

// Помечаем как разосланное за этот день (чтобы не слать повторно).
$pdo->prepare('UPDATE council_meeting SET notified_for = ? WHERE id = 1')->execute([$meetingDay]);

$log("готово: отправлено {$sent}, ошибок {$failed}");
