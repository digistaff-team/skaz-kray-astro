<?php
declare(strict_types=1);

/**
 * Выдача доступов к «Моему поместью»: привязка логин-аккаунта (email + пароль) к
 * поместью. Житель входит по этим email/паролю на /poselenie/vhod и видит только
 * своё поместье.
 *
 *   php bin/household-accounts.php list
 *       — список поместий: id, поляна/участок, поместье, привязанный аккаунт, статус.
 *
 *   php bin/household-accounts.php set <household_id> <email> "<пароль>"
 *       — создать/переиспользовать аккаунт по email, задать пароль, сделать active
 *         и привязать к поместью. Печатает готовые для отправки семье реквизиты.
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Auth;

Config::load(__DIR__ . '/../config/config.php');
$pdo = Database::connect(Config::get('db'));

$cmd = $argv[1] ?? '';

if ($cmd === 'list') {
    $rows = $pdo->query(
        "SELECT h.id, h.glade, h.plot, h.estate_name, h.family_id,
                f.email, f.status,
                (SELECT COUNT(*) FROM residents r WHERE r.household_id = h.id) AS members
         FROM households h
         LEFT JOIN families f ON f.id = h.family_id
         ORDER BY h.sort, h.id"
    )->fetchAll();
    printf("%-4s %-16s %-4s %-22s %-30s %-8s %s\n", 'id', 'поляна', 'уч', 'поместье', 'аккаунт', 'статус', 'чел');
    foreach ($rows as $r) {
        printf("%-4s %-16s %-4s %-22s %-30s %-8s %s\n",
            $r['id'], mb_substr((string) $r['glade'], 0, 16), (string) $r['plot'],
            mb_substr((string) $r['estate_name'], 0, 22),
            (string) ($r['email'] ?? '—'), (string) ($r['status'] ?? '—'), $r['members']);
    }
    exit(0);
}

if ($cmd === 'set') {
    $hid = (int) ($argv[2] ?? 0);
    $email = mb_strtolower(trim((string) ($argv[3] ?? '')));
    $password = (string) ($argv[4] ?? '');
    if ($hid <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        fwrite(STDERR, "Использование: php bin/household-accounts.php set <household_id> <email> \"<пароль ≥8 симв.>\"\n");
        exit(1);
    }
    $h = $pdo->prepare('SELECT * FROM households WHERE id = ?');
    $h->execute([$hid]);
    $house = $h->fetch();
    if (!$house) { fwrite(STDERR, "Поместье id={$hid} не найдено.\n"); exit(1); }

    $hash = Auth::hash($password);
    $now = date('Y-m-d H:i:s');

    $f = $pdo->prepare('SELECT id FROM families WHERE email = ?');
    $f->execute([$email]);
    $fam = $f->fetch();
    if ($fam) {
        $familyId = (int) $fam['id'];
        $pdo->prepare("UPDATE families SET password_hash = ?, status = 'active', approved_at = ? WHERE id = ?")
            ->execute([$hash, $now, $familyId]);
        $action = "обновлён существующий аккаунт";
    } else {
        $name = $house['estate_name'] !== '' ? $house['estate_name'] : ('Поместье #' . $hid);
        $pdo->prepare("INSERT INTO families (email, password_hash, name, status, role, approved_at)
                       VALUES (?, ?, ?, 'active', 'resident', ?)")
            ->execute([$email, $hash, $name, $now]);
        $familyId = (int) $pdo->lastInsertId();
        $action = "создан новый аккаунт";
    }

    // Отвязываем этот аккаунт от других поместий (один аккаунт — одно поместье) и привязываем к нужному.
    $pdo->prepare('UPDATE households SET family_id = NULL WHERE family_id = ? AND id <> ?')->execute([$familyId, $hid]);
    $pdo->prepare('UPDATE households SET family_id = ? WHERE id = ?')->execute([$familyId, $hid]);

    echo "OK: {$action}, привязан к поместью id={$hid} «{$house['estate_name']}» ({$house['glade']}, уч. {$house['plot']}).\n\n";
    echo "Реквизиты для семьи (отправьте им):\n";
    echo "  Адрес входа: https://skaz-kray.ru/poselenie/vhod\n";
    echo "  Логин (email): {$email}\n";
    echo "  Пароль: {$password}\n";
    exit(0);
}

if ($cmd === 'unlink') {
    $hid = (int) ($argv[2] ?? 0);
    if ($hid <= 0) { fwrite(STDERR, "Использование: php bin/household-accounts.php unlink <household_id>\n"); exit(1); }
    $pdo->prepare('UPDATE households SET family_id = NULL WHERE id = ?')->execute([$hid]);
    echo "OK: поместье id={$hid} отвязано от аккаунта (житель сможет выбрать его заново).\n";
    exit(0);
}

fwrite(STDERR, "Команды: list | set <household_id> <email> \"<пароль>\" | unlink <household_id>\n");
exit(1);
