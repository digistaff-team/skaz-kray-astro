<?php
declare(strict_types=1);

/**
 * Рассылка уведомления о встрече Попечительского совета членам совета через
 * @SkazKray_bot. Запускается cron'ом ежедневно в 06:00 UTC (= 09:00 МСК).
 *
 * Логика: если дата встречи (council_meeting.starts_at) == сегодня (МСК) и за
 * этот день ещё не рассылали (notified_for) — шлём всем членам совета с
 * привязанным Telegram: дата/время, место, дежурные, ссылка на повестку в
 * приложении. Идемпотентно (notified_for = дата встречи).
 *
 * Флаги: --dry-run (показать, не отправлять), --force (игнорировать проверку
 * даты/повтора — для ручного теста).
 *
 * Запуск: php8.3 bin/council-meeting-notify.php [--dry-run] [--force]
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Database, TelegramBot, Env};

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

// Текст уведомления.
$lines = [
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
];
$text = implode("\n", $lines);

// Получатели — активные члены совета с привязанным Telegram.
$recipients = $pdo->query(
    "SELECT id, name, telegram_id FROM council_members
     WHERE telegram_id IS NOT NULL AND status = 'active' ORDER BY id"
)->fetchAll(\PDO::FETCH_ASSOC);

$log(($dryRun ? '[DRY-RUN] ' : '') . 'встреча ' . $meetingDay . ', получателей: ' . count($recipients));

if ($dryRun) {
    echo "---- текст ----\n{$text}\n---------------\n";
    foreach ($recipients as $r) { echo "  → {$r['name']} (tg {$r['telegram_id']})\n"; }
    exit(0);
}

$sent = 0; $failed = 0;
foreach ($recipients as $r) {
    if (TelegramBot::sendMessage($botToken, (string) $r['telegram_id'], $text)) {
        $sent++;
    } else {
        $failed++;
        $log("не доставлено: {$r['name']} (tg {$r['telegram_id']})");
    }
}

// Помечаем как разосланное за этот день (чтобы не слать повторно).
$pdo->prepare('UPDATE council_meeting SET notified_for = ? WHERE id = 1')->execute([$meetingDay]);

$log("готово: отправлено {$sent}, ошибок {$failed}");
