<?php
declare(strict_types=1);

/**
 * Импорт справочника жителей поселения из CSV (выгрузка гугл-таблицы) в БД.
 *
 * Наполняет households (поместья) и residents (люди), при наличии email у кого-то
 * из поместья заводит логин-аккаунт families (status=pending) и привязывает его к
 * поместью (households.family_id). Питомцы в CSV не входят — импортируются только люди.
 *
 * По умолчанию — DRY-RUN: ничего не пишет, только печатает, что было бы создано.
 * Боевой прогон:  php bin/import-residents.php --file=/path/residents.csv --commit
 *
 * Колонки CSV (с заголовком):
 *   glade, plot, status, estate, joined, full_name, birth, phone, vk, skills,
 *   community, moved, residence, car, hometown, email, extra, questionnaire, comment, updated
 */

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Auth;

$opts = getopt('', ['file:', 'commit', 'force']);
$file   = $opts['file']   ?? (__DIR__ . '/../config/residents.csv');
$commit = isset($opts['commit']);
$force  = isset($opts['force']);

if (!is_file($file)) {
    fwrite(STDERR, "CSV не найден: {$file}\n");
    fwrite(STDERR, "Использование: php bin/import-residents.php --file=<path.csv> [--commit] [--force]\n");
    exit(1);
}

Config::load(__DIR__ . '/../config/config.php');
$pdo = Database::connect(Config::get('db'));

// ─── Чтение CSV ───────────────────────────────────────────────────────────────
$fh = fopen($file, 'r');
if ($fh === false) { fwrite(STDERR, "Не открыть {$file}\n"); exit(1); }
$header = fgetcsv($fh);
if ($header === false) { fwrite(STDERR, "Пустой CSV\n"); exit(1); }
// Срезаем BOM (UTF-8), который Excel/Google Sheets ставят перед первым заголовком.
// Из-за BOM перед кавычкой fgetcsv не распознаёт первое поле как закавыченное и
// оставляет литеральные кавычки — снимаем и BOM, и обрамляющие кавычки.
if (isset($header[0])) {
    $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $header[0]);
    $header[0] = trim($header[0], "\"");
}
$idx = array_flip(array_map('strval', $header));
$need = ['glade','plot','status','estate','joined','full_name','birth','phone','vk',
         'skills','community','moved','residence','car','hometown','email',
         'extra','questionnaire','comment','updated'];
foreach ($need as $col) {
    if (!array_key_exists($col, $idx)) { fwrite(STDERR, "В CSV нет колонки '{$col}'\n"); exit(1); }
}
$get = static function (array $row, array $idx, string $col): string {
    $i = $idx[$col];
    return isset($row[$i]) ? trim((string) $row[$i]) : '';
};

$rows = [];
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string) $r[0]) === '') { continue; } // пустая строка
    $rows[] = $r;
}
fclose($fh);

// ─── Группировка людей в поместья по совпадению соседних строк ─────────────────
// Ключ поместья — (поляна|участок|поместье|дата вступления|статус). Строки в CSV
// идут в исходном порядке таблицы, поместья не перемешаны, поэтому смена ключа =
// начало нового поместья.
$households = [];   // [key => ['meta'=>..., 'people'=>[...]]]
$order = [];        // порядок ключей
$prevKey = null;
foreach ($rows as $r) {
    $name = $get($r, $idx, 'full_name');
    if ($name === '' || preg_match('~^:?-?:?$~u', $name)) { continue; } // мусор/разделители
    $meta = [
        'glade'  => $get($r, $idx, 'glade'),
        'plot'   => $get($r, $idx, 'plot'),
        'estate' => $get($r, $idx, 'estate'),
        'joined' => $get($r, $idx, 'joined'),
        'status' => $get($r, $idx, 'status'),
    ];
    $key = implode('|', $meta);
    if ($key !== $prevKey) { $order[] = $key; $households[$key] = ['meta' => $meta, 'people' => []]; }
    $prevKey = $key;

    $quest = $get($r, $idx, 'questionnaire');
    if ($quest === '') { $quest = $get($r, $idx, 'extra'); } // ссылка иногда в соседней колонке
    $households[$key]['people'][] = [
        'full_name'      => $name,
        'birth_raw'      => $get($r, $idx, 'birth'),
        'birth_date'     => parse_birth_date($get($r, $idx, 'birth')),
        'phone'          => $get($r, $idx, 'phone'),
        'vk'             => $get($r, $idx, 'vk'),
        'skills'         => $get($r, $idx, 'skills'),
        'community_role' => $get($r, $idx, 'community'),
        'moved_text'     => $get($r, $idx, 'moved'),
        'residence'      => $get($r, $idx, 'residence'),
        'car'            => $get($r, $idx, 'car'),
        'hometown'       => $get($r, $idx, 'hometown'),
        'email'          => $get($r, $idx, 'email'), // сырой — в справочник кладём как есть
        'questionnaire'  => $quest,
        'comment'        => $get($r, $idx, 'comment'),
        'updated_text'   => $get($r, $idx, 'updated'),
    ];
}

/** dd.mm.yyyy → Y-m-d; иначе null (сырой текст всё равно сохраняется в birth_raw). */
function parse_birth_date(string $s): ?string
{
    if (preg_match('~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', trim($s), $m)) {
        $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) { return sprintf('%04d-%02d-%02d', $y, $mo, $d); }
    }
    return null;
}

/** Возвращает валидный email в нижнем регистре — или '' (для логин-аккаунта). */
function valid_email(string $s): string
{
    $s = trim($s);
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? mb_strtolower($s) : '';
}

// ─── Подсчёт для отчёта ───────────────────────────────────────────────────────
$totalPeople = 0; $withEmail = 0; $withBirth = 0;
$accountsPlan = []; // key => email первого взрослого с почтой
foreach ($order as $key) {
    $h = $households[$key];
    $totalPeople += count($h['people']);
    foreach ($h['people'] as $p) {
        if (valid_email($p['email']) !== '') { $withEmail++; }
        if ($p['birth_date'] !== null) { $withBirth++; }
    }
    foreach ($h['people'] as $p) {
        $ve = valid_email($p['email']);
        if ($ve !== '') { $accountsPlan[$key] = $ve; break; } // первый с валидным email
    }
}

echo "═══ ИМПОРТ ЖИТЕЛЕЙ ═══\n";
echo "Файл:        {$file}\n";
echo "Режим:       " . ($commit ? "БОЕВОЙ (--commit)" : "DRY-RUN (запись выключена)") . "\n";
echo "Поместий:    " . count($order) . "\n";
echo "Людей:       {$totalPeople}\n";
echo "  из них с email:        {$withEmail}\n";
echo "  с распознанной ДР:     {$withBirth}\n";
echo "Аккаунтов к созданию:    " . count($accountsPlan) . " (по одному на поместье, где есть email)\n";
echo "───────────────────────────────────────────\n";
// Первые несколько поместий для визуальной проверки
$preview = array_slice($order, 0, 4);
foreach ($preview as $key) {
    $h = $households[$key];
    $m = $h['meta'];
    $acc = $accountsPlan[$key] ?? '—';
    echo "• [{$m['glade']} / уч.{$m['plot']}] «{$m['estate']}» (вступ.: {$m['joined']}), аккаунт: {$acc}\n";
    foreach ($h['people'] as $p) {
        $mail = $p['email'] !== '' ? " <{$p['email']}>" : '';
        echo "    — {$p['full_name']} ({$p['birth_raw']}){$mail}\n";
    }
}
echo "  … и ещё " . max(0, count($order) - count($preview)) . " поместий\n";
echo "───────────────────────────────────────────\n";

if (!$commit) {
    echo "DRY-RUN: ничего не записано. Для боевого прогона добавьте --commit\n";
    exit(0);
}

// ─── Боевой импорт ────────────────────────────────────────────────────────────
$existing = (int) $pdo->query('SELECT COUNT(*) FROM households')->fetchColumn();
if ($existing > 0 && !$force) {
    fwrite(STDERR, "В households уже {$existing} записей. Повторный импорт остановлен. Используйте --force, чтобы всё равно добавить.\n");
    exit(1);
}

$insH = $pdo->prepare(
    'INSERT INTO households (glade, plot, estate_name, status_raw, joined_text, family_id, sort)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$insR = $pdo->prepare(
    'INSERT INTO residents
       (household_id, full_name, birth_raw, birth_date, phone, vk, skills, community_role,
        moved_text, residence, car, hometown, email, questionnaire, comment, updated_text, sort)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$findFam = $pdo->prepare('SELECT id FROM families WHERE email = ?');
$insFam  = $pdo->prepare(
    "INSERT INTO families (email, password_hash, name, status, role)
     VALUES (?, ?, ?, 'pending', 'resident')"
);

$pdo->beginTransaction();
$hCount = 0; $rCount = 0; $accCreated = 0; $accLinked = 0;
$sort = 0;
foreach ($order as $key) {
    $h = $households[$key];
    $m = $h['meta'];

    // Логин-аккаунт поместья (если есть email у кого-то) — создаём или переиспользуем.
    $familyId = null;
    if (isset($accountsPlan[$key])) {
        $email = $accountsPlan[$key];
        $findFam->execute([$email]);
        $famRow = $findFam->fetch();
        if ($famRow) {
            $familyId = (int) $famRow['id'];
            $accLinked++;
        } else {
            $famName = $m['estate'] !== '' ? $m['estate'] : $h['people'][0]['full_name'];
            $hash = Auth::hash(bin2hex(random_bytes(16))); // случайный; вход через «Восстановить пароль»
            $insFam->execute([$email, $hash, $famName]);
            $familyId = (int) $pdo->lastInsertId();
            $accCreated++;
        }
    }

    $insH->execute([$m['glade'], $m['plot'], $m['estate'], $m['status'], $m['joined'], $familyId, $sort]);
    $householdId = (int) $pdo->lastInsertId();
    $hCount++;
    $sort++;

    $rsort = 0;
    foreach ($h['people'] as $p) {
        $insR->execute([
            $householdId, $p['full_name'], $p['birth_raw'], $p['birth_date'], $p['phone'], $p['vk'],
            $p['skills'] !== '' ? $p['skills'] : null,
            $p['community_role'] !== '' ? $p['community_role'] : null,
            $p['moved_text'], $p['residence'], $p['car'], $p['hometown'], $p['email'],
            $p['questionnaire'], $p['comment'] !== '' ? $p['comment'] : null, $p['updated_text'], $rsort,
        ]);
        $rCount++;
        $rsort++;
    }
}
$pdo->commit();

echo "ГОТОВО: поместий {$hCount}, людей {$rCount}, аккаунтов создано {$accCreated}, привязано к существующим {$accLinked}\n";
