<?php
declare(strict_types=1);

/**
 * Авто-перенос встречи Совета на следующий понедельник ПОСЛЕ состоявшейся.
 * Запускается cron'ом в понедельник 23:59 МСК (20:59 UTC).
 *
 * «Состоялась» = дата встречи (council_meeting.starts_at) == сегодня (понедельник).
 * Тогда: дата → +7 дней, ротация → следующий дежурный, повестка/секретарь сброшены.
 * Если встреча перенесена (её дата не сегодня) — НЕ двигаем: тот же дежурный
 * остаётся на перенесённую встречу, график сдвигается сам собой.
 *
 * Флаг --force игнорирует проверку даты (для ручного прогона).
 * Запуск: php8.3 bin/council-meeting-advance.php [--force]
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Database, Env, CouncilDutyRotation};
use SkazResidents\Repository\CouncilMeetingRepository;

$force = in_array('--force', $argv, true);

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

$r = CouncilDutyRotation::advance();
$log("встреча состоялась ({$meetingDay}) → перенос на {$r['date']}, следующий дежурный: {$r['chair']}");
