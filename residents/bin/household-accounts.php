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
 *
 *   php bin/household-accounts.php detach <family_id>
 *       — отвязать аккаунт-семью от поместья целиком: снять первичное владение
 *         (households.family_id), совладение (household_owners) и ожидающие заявки
 *         (household_join_requests). Семья остаётся активной — после этого открывается
 *         выбор поместья заново (/poselenie/moye-pomestie/vybor). Печатает, что было
 *         отвязано; ключ --notify дополнительно шлёт семье сообщение ботом.
 *
 *   php bin/household-accounts.php release <household_id>
 *       — освободить ПОМЕСТЬЕ от текущего аккаунта: снять первичное владение
 *         (households.family_id), всех совладельцев (household_owners), ожидающие
 *         заявки и фамилию привязки (claim_surname). Жители (resident) и название
 *         сохраняются — поместье становится свободным для повторного выбора на
 *         /poselenie/moye-pomestie/vybor (с подтверждением фамилии).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/timezone.php';   // время приложения — Москва (UTC+3)

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

if ($cmd === 'detach') {
    $args = array_slice($argv, 2);
    $notify = in_array('--notify', $args, true);
    $args = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));
    $familyId = (int) ($args[0] ?? 0);
    if ($familyId <= 0) {
        fwrite(STDERR, "Использование: php bin/household-accounts.php detach <family_id> [--notify]\n");
        exit(1);
    }
    $f = $pdo->prepare('SELECT * FROM families WHERE id = ?');
    $f->execute([$familyId]);
    $family = $f->fetch();
    if (!$family) { fwrite(STDERR, "Семья (аккаунт) id={$familyId} не найдена.\n"); exit(1); }

    $pdo->beginTransaction();
    try {
        // 1. Поместья, где семья — первичный владелец (households.family_id).
        $h = $pdo->prepare('SELECT id, glade, plot, estate_name FROM households WHERE family_id = ?');
        $h->execute([$familyId]);
        $primary = $h->fetchAll();

        // 2. Поместья, где семья — совладелец (household_owners).
        $o = $pdo->prepare('SELECT h.id, h.glade, h.plot, h.estate_name
                            FROM household_owners o JOIN households h ON h.id = o.household_id
                            WHERE o.family_id = ?');
        $o->execute([$familyId]);
        $shared = $o->fetchAll();

        // 3. Ожидающие заявки в совладельцы (на всякое — снимаем все).
        $r = $pdo->prepare('SELECT COUNT(*) FROM household_join_requests WHERE family_id = ?');
        $r->execute([$familyId]);
        $requests = (int) $r->fetchColumn();

        // Снимаем всё: первичное владение, совладение, заявки (как detachAccount в репозитории).
        $pdo->prepare('UPDATE households SET family_id = NULL WHERE family_id = ?')->execute([$familyId]);
        $pdo->prepare('DELETE FROM household_owners WHERE family_id = ?')->execute([$familyId]);
        $pdo->prepare('DELETE FROM household_join_requests WHERE family_id = ?')->execute([$familyId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Ошибка отвязки: " . $e->getMessage() . "\n");
        exit(1);
    }

    $label = trim(($family['name'] ?? '') . ' (' . ($family['email'] ?? '') . ')');
    echo "OK: аккаунт id={$familyId} «{$label}» отвязан от поместья.\n";
    if ($primary) {
        foreach ($primary as $p) {
            echo "  − первичное владение: поместье id={$p['id']} «{$p['estate_name']}» ({$p['glade']}, уч. {$p['plot']}) — поместье стало свободным.\n";
        }
    }
    if ($shared) {
        foreach ($shared as $s) {
            echo "  − совладение снято: поместье id={$s['id']} «{$s['estate_name']}» ({$s['glade']}, уч. {$s['plot']}).\n";
        }
    }
    if ($requests > 0) {
        echo "  − заявок в совладельцы снято: {$requests}.\n";
    }
    if (!$primary && !$shared && $requests === 0) {
        echo "  Связей с поместьями не было — нечего отвязывать (аккаунт уже свободный).\n";
    }
    echo "Семья осталась активной: /poselenie/moye-pomestie/vybor — выбор поместья снова доступен.\n";

    if ($notify) {
        $lines = [
            'Ваш аккаунт отвязан от поместья.',
            'Если это ошибка — напишите редактору поселения.',
            'Выбрать поместье заново можно в приложении.',
        ];
        try {
            \SkazResidents\Service\BotNotify::toFamily($familyId,
                static fn(string $base): string => \SkazResidents\Service\BotNotify::personalText($lines, 'Выбрать поместье: ', $base, '/poselenie/moye-pomestie/vybor'));
            echo "Уведомление семье отправлено ботом.\n";
        } catch (\Throwable $e) {
            echo "Уведомление не ушло (" . $e->getMessage() . ") — не критично.\n";
        }
    }
    exit(0);
}

if ($cmd === 'release') {
    $hid = (int) ($argv[2] ?? 0);
    if ($hid <= 0) {
        fwrite(STDERR, "Использование: php bin/household-accounts.php release <household_id>\n");
        exit(1);
    }
    $h = $pdo->prepare('SELECT * FROM households WHERE id = ?');
    $h->execute([$hid]);
    $house = $h->fetch();
    if (!$house) { fwrite(STDERR, "Поместье id={$hid} не найдено.\n"); exit(1); }

    $pdo->beginTransaction();
    try {
        // Кого отвязываем (для отчёта).
        $prevFamily = null;
        if (!empty($house['family_id'])) {
            $p = $pdo->prepare('SELECT id, name, email FROM families WHERE id = ?');
            $p->execute([(int) $house['family_id']]);
            $prevFamily = $p->fetch() ?: null;
        }
        $owners = $pdo->prepare('SELECT o.family_id, f.name, f.email
                                 FROM household_owners o JOIN families f ON f.id = o.family_id
                                 WHERE o.household_id = ?');
        $owners->execute([$hid]);
        $ownerRows = $owners->fetchAll();

        $reqs = $pdo->prepare('SELECT COUNT(*) FROM household_join_requests WHERE household_id = ?');
        $reqs->execute([$hid]);
        $requests = (int) $reqs->fetchColumn();

        // Снимаем всё: первичное владение, всех совладельцев, заявки, фамилию привязки.
        // Жители (resident) и название НЕ трогаем — поместье остаётся собой.
        $pdo->prepare('UPDATE households SET family_id = NULL WHERE id = ?')->execute([$hid]);
        $pdo->prepare('DELETE FROM household_owners WHERE household_id = ?')->execute([$hid]);
        $pdo->prepare('DELETE FROM household_join_requests WHERE household_id = ?')->execute([$hid]);
        $pdo->prepare('UPDATE households SET claim_surname = NULL WHERE id = ?')->execute([$hid]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Ошибка освобождения: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "OK: поместье id={$hid} «{$house['estate_name']}» ({$house['glade']}, уч. {$house['plot']}) освобождено от аккаунта.\n";
    if ($prevFamily) {
        echo "  − первичное владение снято с: {$prevFamily['name']} ({$prevFamily['email']}).\n";
    }
    foreach ($ownerRows as $o) {
        echo "  − совладелец отвязан: {$o['name']} ({$o['email']}).\n";
    }
    if ($requests > 0) { echo "  − заявок в совладельцы снято: {$requests}.\n"; }
    if (!$prevFamily && !$ownerRows && $requests === 0) {
        echo "  Поместье уже было свободным.\n";
    }
    echo "Поместье теперь свободно: любой житель может привязать его на /poselenie/moye-pomestie/vybor (подтверждение — фамилия из жителей поместья).\n";
    exit(0);
}

fwrite(STDERR, "Команды: list | set <household_id> <email> \"<пароль>\" | unlink <household_id> | detach <family_id> [--notify] | release <household_id>\n");
exit(1);
