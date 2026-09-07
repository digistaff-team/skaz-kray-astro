<?php
declare(strict_types=1);

/**
 * Сид ростера Попечительского совета для входа через Telegram.
 * Заводит записи известного состава (CouncilData::members()) как claimable-строки
 * (telegram_id=NULL): член входит через Mini App, вводит фамилию → привязывается.
 * Идемпотентно: существующие по имени пропускаются. Ставит Наталью Нецветову
 * текущим Дежурным председателем.
 *
 * Требует уже накатанную config/council-telegram-schema.sql (колонки telegram_id/surname).
 * Запуск на сервере:  php8.3 bin/council-roster-seed.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\{Config, Database, Auth, CouncilData};
use SkazResidents\Repository\CouncilMemberRepository;

Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));

$repo = new CouncilMemberRepository();
$dutyName = 'Наталья Нецветова';

$created = 0; $skipped = 0;
foreach (CouncilData::members() as $m) {
    $name = trim((string) $m['name']);
    if ($name === '') { continue; }

    if ($repo->findByName($name)) { $skipped++; continue; }

    // Фамилия = последнее слово имени («Имя Фамилия»).
    $parts   = preg_split('/\s+/u', $name);
    $surname = (string) end($parts);

    // Синтетические email/пароль — вход только через Telegram, ими не пользуются.
    $email = 'sovet-' . substr(md5($name), 0, 12) . '@telegram.local';
    $hash  = Auth::hash(bin2hex(random_bytes(16)));

    $repo->createRosterMember($name, $surname, $email, $hash);
    $created++;
    echo "  + {$name} (фамилия: {$surname})\n";
}

echo "Заведено: {$created}, пропущено (уже были): {$skipped}\n";

// Назначаем текущего дежурного председателя.
$duty = $repo->findByName($dutyName);
if ($duty) {
    $repo->setDutyChair((int) $duty['id']);
    echo "Дежурный председатель: {$dutyName}\n";
} else {
    echo "ВНИМАНИЕ: {$dutyName} не найдена — дежурный не назначен\n";
}
