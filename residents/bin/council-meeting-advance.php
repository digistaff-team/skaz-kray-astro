<?php
declare(strict_types=1);

/**
 * Авто-перенос встречи Совета на следующий понедельник ПОСЛЕ состоявшейся.
 * Запускается cron'ом в понедельник 21:00 МСК (18:00 UTC; сервер живёт в UTC).
 *
 * «Состоялась» = дата встречи (council_meeting.starts_at) == сегодня (понедельник).
 * Тогда: дата → +7 дней, ротация → следующий дежурный, повестка/секретарь сброшены.
 * Если встреча перенесена (её дата не сегодня) — НЕ двигаем: тот же дежурный
 * остаётся на перенесённую встречу, график сдвигается сам собой.
 *
 * После ротации новому Дежурному председателю уходит личное сообщение от бота
 * (CouncilDutyNotify) — чтобы он узнал о дежурстве сразу, а не из карточки встречи.
 *
 * Флаги: --force (игнорировать проверку даты), --dry-run (показать, кто станет
 * дежурным и какой текст ему уйдёт, ничего не меняя и не отправляя).
 * Запуск: php8.3 bin/council-meeting-advance.php [--force] [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

use SkazResidents\{Config, Database, Env, CouncilDutyRotation};
use SkazResidents\Repository\CouncilMeetingRepository;
use SkazResidents\Service\CouncilDutyNotify;

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));

$log = static function (string $m): void { echo '[' . gmdate('Y-m-d H:i:s') . " UTC] {$m}\n"; };

$repo = new CouncilMeetingRepository();
$m = $repo->get();
$startsAt = (string) ($m['startsAt'] ?? '');
if ($startsAt === '') { $log('нет даты встречи — выход'); exit(0); }

$now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
$todayMsk   = $now->format('Y-m-d');
$dow        = (int) $now->format('N');            // 1 = понедельник
$meetingDay = substr($startsAt, 0, 10);

if (!$force) {
    if ($dow !== 1) { $log("сегодня не понедельник ({$todayMsk}) — выход"); exit(0); }
    if ($meetingDay !== $todayMsk) {
        $log("встреча не сегодня (назначена на {$meetingDay}) — вероятно перенесена, ротацию не двигаем");
        exit(0);
    }
}

if ($dryRun) {
    $next = new DateTime($startsAt, new DateTimeZone('Europe/Moscow'));
    $next->modify('+7 days');
    $chair = CouncilDutyRotation::nameForIndex($repo->rotationIndex() + 1);
    $when  = CouncilMeetingRepository::formatDisplay($next->format('Y-m-d H:i:s'), null);
    $log("[DRY-RUN] следующий дежурный: {$chair}, встреча {$when}");
    echo "---- сообщение дежурному ----\n"
       . CouncilDutyNotify::text($chair, $when, (string) ($m['place'] ?? ''), CouncilDutyNotify::link(CouncilDutyNotify::base('tg')))
       . "\n[кнопка] ✅ " . CouncilDutyNotify::ACK_LABEL
       . "\n-----------------------------\n";
    exit(0);
}

$r = CouncilDutyRotation::advance();
$log("встреча состоялась ({$meetingDay}) → перенос на {$r['date']}, следующий дежурный: {$r['chair']}");

// Новому дежурному — личное сообщение от бота. Дату и место берём уже из
// обновлённой карточки встречи, чтобы написать то же, что человек увидит в приложении.
$next = $repo->get();
if ($r['chair'] !== '' && CouncilDutyNotify::newChair($r['chair'], (string) ($next['date'] ?? ''), (string) ($next['place'] ?? ''), $repo->rotationIndex())) {
    $log("уведомление отправлено: {$r['chair']}");
} elseif ($r['chair'] !== '') {
    $log("уведомление НЕ отправлено: {$r['chair']} (нет привязки к боту или ошибка отправки)");
}
