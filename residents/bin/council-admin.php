<?php
declare(strict_types=1);

/**
 * Заведение (или сброс пароля) администратора Попечительского совета из CLI.
 * Первый admin создаётся так, дальше он заводит остальных через веб-интерфейс.
 *
 * Запуск на сервере:
 *   php bin/council-admin.php <email> "<Имя>" "<пароль>"
 * Если член совета с таким email уже есть — ему обновляется пароль и роль admin.
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Auth;
use SkazResidents\Repository\CouncilMemberRepository;

if ($argc < 4) {
    fwrite(STDERR, "Использование: php bin/council-admin.php <email> \"<Имя>\" \"<пароль>\"\n");
    exit(1);
}

[$_, $email, $name, $password] = $argv;

Config::load(__DIR__ . '/../config/config.php');
Database::connect(Config::get('db'));

$repo = new CouncilMemberRepository();
$hash = Auth::hash($password);

$existing = $repo->findByEmail($email);
if ($existing) {
    $repo->updatePassword((int) $existing['id'], $hash);
    // Поднимаем до admin и снимаем блокировку, если была.
    Database::pdo()->prepare('UPDATE council_members SET role = \'admin\', status = \'active\' WHERE id = ?')
        ->execute([(int) $existing['id']]);
    echo "Обновлён администратор совета: {$email}\n";
} else {
    $repo->create($email, $hash, $name, 'admin');
    echo "Создан администратор совета: {$email}\n";
}
