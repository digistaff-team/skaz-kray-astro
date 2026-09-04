# Модуль «Бюджет Общего дома» — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Помесячный учёт прихода/расхода по статьям в разделе «Попечительский совет» skaz-kray.ru + наглядный read-only отчёт «Бюджет Общего дома» для всех авторизованных жителей.

**Architecture:** Встраивается в существующее PHP-приложение `residents/` (чистый PHP 8.3 + PDO, тонкий роутер, шаблоны-партиалы, namespace `SkazResidents`, БД MariaDB `skazkray_residents`, тесты на SQLite in-memory). Две новые таблицы (справочник статей + операции), два репозитория, сервис-сборщик отчёта `LedgerReport` (общий источник правды для совета и жителей), два контроллера (совет — правит, жители — read-only), общий партиал наглядной части. Никакого JS — статичная вёрстка (полоски по статьям на CSS). Стиль — фирменные токены сайта.

**Tech Stack:** PHP 8.3, PDO (MariaDB прод / SQLite тесты), PHPUnit 11, существующие сервисы `Csrf`/`Flash`/`View`/`Upload`/`Config`/`ImageRepository`/`CouncilAuth`/`Auth`.

---

## Ключевые соглашения (прочитать перед стартом)

- **Гарды доступа:** все мутации (операции, справочник статей) — `CouncilAuth::requireLogin()` (только члены совета); операции со справочником дополнительно `CouncilAuth::requireAdmin()`. Отчёт жителям — `Auth::requireLogin()` (любая вошедшая семья). CSRF (`Csrf::check`) на КАЖДОМ POST.
- **Правило раздела:** не использовать `MAX(a,b)`/`GREATEST`/`LEAST`/диалектные функции дат в SQL. Все агрегации/группировки по месяцам считаются в PHP из одного запроса `allWithCategory()`. Месяц операции = `substr($entry_date, 0, 7)` (`YYYY-MM`).
- **Деньги:** в БД `DECIMAL(12,2)` (MariaDB) / `REAL` (SQLite), в PHP приводим `(float)`; ввод парсим `max(0, (float) str_replace(',', '.', $raw))`.
- **Тесты запускаются:** локально `php vendor/bin/phpunit ...`; на сервере — `php8.3 vendor/bin/phpunit ...` (там CLI = php8.3). В командах ниже пишется `php` — на сервере замените на `php8.3`.
- **Коммиты:** частые, после каждой зелёной задачи. Ветка уже создана: `feature/council-budget-ledger`.
- Каждый новый PHP-файл проверяется `php -l <файл>` (нет синтаксических ошибок) перед коммитом.

## Файловая структура

**Создать:**
- `residents/config/council-ledger-schema.sql` — MariaDB-схема + сид статей (прод, накатывается руками).
- `residents/src/Repository/CouncilCategoryRepository.php` — CRUD справочника статей.
- `residents/src/Repository/CouncilLedgerRepository.php` — операции + агрегации.
- `residents/src/Service/LedgerReport.php` — сборка модели отчёта.
- `residents/src/Controller/Council/LedgerController.php` — страница совета (правка).
- `residents/src/Controller/BudgetController.php` — отчёт жителей (read-only).
- `residents/src/templates/partials/ledger_report.php` — наглядная часть (таблица/полоски/операции), общая.
- `residents/src/templates/council/ledger.php` — совет: партиал + формы ввода/правки.
- `residents/src/templates/council/categories.php` — совет-админ: справочник статей.
- `residents/src/templates/budget/report.php` — жители: партиал read-only.
- `residents/tests/CouncilCategoryRepositoryTest.php`
- `residents/tests/CouncilLedgerRepositoryTest.php`
- `residents/tests/LedgerReportTest.php`

**Изменить:**
- `residents/tests/schema.sqlite.sql` — +2 таблицы.
- `residents/tests/SchemaTest.php` — +2 имени в ожидаемый список.
- `residents/public/index.php` — маршруты.
- `residents/src/templates/council/layout.php` — пункт меню «Бухгалтерия».
- `residents/src/templates/council/home.php` — ссылка «Бухгалтерия» → `/sovet/buhgalteriya`.
- `residents/src/templates/layout.php` — пункт меню «Бюджет Общего дома».
- `residents/src/templates/cabinet/index.php` — секция-ссылка «Бюджет Общего дома».
- `residents/public/assets/residents.css` — стили `.ledger-*`/`.months`/`.bar-*`.

---

## Task 1: Схема БД (SQLite-тест + MariaDB-прод)

**Files:**
- Modify: `residents/tests/schema.sqlite.sql` (в конец файла)
- Modify: `residents/tests/SchemaTest.php:14-22`
- Create: `residents/config/council-ledger-schema.sql`

- [ ] **Step 1: Добавить таблицы в SQLite-схему тестов**

В конец `residents/tests/schema.sqlite.sql` дописать:

```sql
CREATE TABLE council_ledger_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                 -- income | expense
    name TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE council_ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                 -- income | expense (дублирует kind статьи)
    category_id INTEGER NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    entry_date TEXT NOT NULL,           -- YYYY-MM-DD
    note TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

- [ ] **Step 2: Обновить ожидаемый список таблиц в SchemaTest**

В `residents/tests/SchemaTest.php` заменить массив ожидаемых имён (он отсортирован по алфавиту — вставить два новых имени на свои места, сразу после `council_members`? нет: алфавит — `council_ledger_categories`/`council_ledger_entries` идут между `council_members`? Нет: `council_l...` < `council_m...`, поэтому ДО `council_members`). Итоговый массив:

```php
        $this->assertSame(
            [
                'book_loans', 'books',
                'council_ledger_categories', 'council_ledger_entries',
                'council_members', 'council_password_resets', 'council_subtasks', 'council_tasks',
                'diary_entries', 'families', 'images', 'login_attempts', 'password_resets', 'products',
                'tool_loans', 'tools', 'trip_bookings', 'trips',
            ],
            $names
        );
```

- [ ] **Step 3: Прогнать SchemaTest — должен пройти**

Run: `php vendor/bin/phpunit --filter SchemaTest`
Expected: PASS (1 тест, зелёный). Если FAIL «arrays not identical» — сверить порядок имён (строго по возрастанию ASCII, `council_ledger_*` перед `council_members`).

- [ ] **Step 4: Создать MariaDB-схему прод (с сидом статей)**

Create `residents/config/council-ledger-schema.sql`:

```sql
-- Схема модуля «Бюджет Общего дома» (учёт прихода/расхода совета).
-- Накатывается ОДИН раз вручную в ту же БД, что раздел жителей/совета:
--   mysql skazkray_residents < config/council-ledger-schema.sql
-- Статьи не удаляются физически при наличии операций — только архивируются (is_active=0).

CREATE TABLE council_ledger_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(16)  NOT NULL,                  -- income | expense
    name       VARCHAR(160) NOT NULL,
    position   INT          NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ledger_cat_kind (kind, is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_ledger_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        VARCHAR(16)   NOT NULL,                 -- income | expense (дублирует kind статьи)
    category_id INT UNSIGNED  NOT NULL,
    amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    entry_date  DATE          NOT NULL,
    note        VARCHAR(300)  NOT NULL DEFAULT '',
    author      VARCHAR(160)  NOT NULL DEFAULT '',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ledger_category FOREIGN KEY (category_id)
        REFERENCES council_ledger_categories(id),
    INDEX idx_ledger_date (entry_date),
    INDEX idx_ledger_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Стартовый справочник статей (админ совета правит через интерфейс).
INSERT INTO council_ledger_categories (kind, name, position) VALUES
('income', 'Из Фонда общего дома', 0),
('income', 'Коммерческая аренда',  1),
('income', 'Школа',                2),
('expense','Дороги и въезд',       0),
('expense','Электрика',            1),
('expense','Вывоз мусора',         2),
('expense','Праздники',            3),
('expense','Инвентарь',            4);
```

- [ ] **Step 5: Коммит**

```bash
git add residents/tests/schema.sqlite.sql residents/tests/SchemaTest.php residents/config/council-ledger-schema.sql
git commit -m "feat(ledger): схема БД бюджета — справочник статей и операции"
```

---

## Task 2: CouncilCategoryRepository (справочник статей, TDD)

**Files:**
- Create: `residents/src/Repository/CouncilCategoryRepository.php`
- Test: `residents/tests/CouncilCategoryRepositoryTest.php`

- [ ] **Step 1: Написать падающий тест**

Create `residents/tests/CouncilCategoryRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilCategoryRepository;

final class CouncilCategoryRepositoryTest extends TestCase
{
    private CouncilCategoryRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilCategoryRepository();
    }

    public function test_create_and_list_by_kind_ordered_by_position(): void
    {
        $this->repo->create('income', 'Аренда');
        $this->repo->create('income', 'Школа');
        $this->repo->create('expense', 'Дороги');

        $income = $this->repo->listByKind('income', true);
        $this->assertCount(2, $income);
        $this->assertSame('Аренда', $income[0]['name']); // position 0 раньше 1
        $this->assertSame('Школа', $income[1]['name']);

        $this->assertCount(1, $this->repo->listByKind('expense', true));
    }

    public function test_archive_hides_from_active_but_kept_in_full(): void
    {
        $id = $this->repo->create('expense', 'Праздники');
        $this->repo->setActive($id, false);

        $this->assertCount(0, $this->repo->listByKind('expense', true));
        $this->assertCount(1, $this->repo->listByKind('expense', false));

        $this->repo->setActive($id, true);
        $this->assertCount(1, $this->repo->listByKind('expense', true));
    }

    public function test_rename(): void
    {
        $id = $this->repo->create('income', 'Старое');
        $this->repo->rename($id, 'Новое');
        $this->assertSame('Новое', $this->repo->find($id)['name']);
    }
}
```

- [ ] **Step 2: Прогнать — должен упасть**

Run: `php vendor/bin/phpunit --filter CouncilCategoryRepositoryTest`
Expected: FAIL — `Class "SkazResidents\Repository\CouncilCategoryRepository" not found`.

- [ ] **Step 3: Реализовать репозиторий**

Create `residents/src/Repository/CouncilCategoryRepository.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Справочник статей бюджета: приход (income) и расход (expense).
 * Статьи не удаляются физически — только архивируются (is_active=0),
 * чтобы исторические операции по статье не теряли имя.
 * Сортировка по position — в SQL (переносимо: обычный ORDER BY).
 */
final class CouncilCategoryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $kind, string $name): int
    {
        $countSt = $this->db->prepare('SELECT COUNT(*) FROM council_ledger_categories WHERE kind = ?');
        $countSt->execute([$kind]);
        $position = (int) $countSt->fetchColumn();

        $st = $this->db->prepare(
            'INSERT INTO council_ledger_categories (kind, name, position) VALUES (?, ?, ?)'
        );
        $st->execute([$kind, mb_substr($name, 0, 160), $position]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_ledger_categories WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByKind(string $kind, bool $onlyActive): array
    {
        $sql = 'SELECT * FROM council_ledger_categories WHERE kind = ?';
        if ($onlyActive) { $sql .= ' AND is_active = 1'; }
        $sql .= ' ORDER BY position ASC, id ASC';
        $st = $this->db->prepare($sql);
        $st->execute([$kind]);
        return $st->fetchAll();
    }

    public function rename(int $id, string $name): void
    {
        $st = $this->db->prepare('UPDATE council_ledger_categories SET name = ? WHERE id = ?');
        $st->execute([mb_substr($name, 0, 160), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $st = $this->db->prepare('UPDATE council_ledger_categories SET is_active = ? WHERE id = ?');
        $st->execute([$active ? 1 : 0, $id]);
    }
}
```

- [ ] **Step 4: Прогнать — должен пройти**

Run: `php vendor/bin/phpunit --filter CouncilCategoryRepositoryTest`
Expected: PASS (3 теста). Затем `php -l residents/src/Repository/CouncilCategoryRepository.php` → «No syntax errors».

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Repository/CouncilCategoryRepository.php residents/tests/CouncilCategoryRepositoryTest.php
git commit -m "feat(ledger): репозиторий справочника статей бюджета"
```

---

## Task 3: CouncilLedgerRepository (операции + агрегации, TDD)

**Files:**
- Create: `residents/src/Repository/CouncilLedgerRepository.php`
- Test: `residents/tests/CouncilLedgerRepositoryTest.php`

- [ ] **Step 1: Написать падающий тест**

Create `residents/tests/CouncilLedgerRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\CouncilCategoryRepository;

final class CouncilLedgerRepositoryTest extends TestCase
{
    private CouncilLedgerRepository $repo;
    private int $incomeCat;
    private int $roadCat;
    private int $elecCat;

    protected function setUp(): void
    {
        make_test_db();
        $cats = new CouncilCategoryRepository();
        $this->incomeCat = $cats->create('income', 'Из Фонда общего дома');
        $this->roadCat   = $cats->create('expense', 'Дороги и въезд');
        $this->elecCat   = $cats->create('expense', 'Электрика');
        $this->repo = new CouncilLedgerRepository();
    }

    public function test_create_and_find(): void
    {
        $id = $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'Сергей Ш.');
        $row = $this->repo->find($id);
        $this->assertSame('expense', $row['kind']);
        $this->assertSame(31000.0, (float) $row['amount']);
        $this->assertSame('2026-08-03', $row['entry_date']);
    }

    public function test_sums_by_month_and_months_newest_first(): void
    {
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-07-05', 'Взносы июль', 'А');
        $this->repo->create('expense', $this->roadCat,   28900.0, '2026-07-15', 'Дорога',      'А');
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы авг',  'А');
        $this->repo->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'Щиток',       'А');

        $sums = $this->repo->sumsByMonth();
        $this->assertSame(42000.0, $sums['2026-07']['income']);
        $this->assertSame(28900.0, $sums['2026-07']['expense']);
        $this->assertSame(42000.0, $sums['2026-08']['income']);
        $this->assertSame(62400.0, $sums['2026-08']['expense']);

        $this->assertSame(['2026-08', '2026-07'], $this->repo->monthsWithData()); // новые сверху
    }

    public function test_expense_by_category_desc(): void
    {
        $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'А');
        $this->repo->create('expense', $this->elecCat, 12400.0, '2026-08-28', 'Автомат', 'А');
        $this->repo->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы', 'А'); // приход не в разбивке

        $rows = $this->repo->expenseByCategory('2026-08');
        $this->assertCount(2, $rows);
        $this->assertSame('Дороги и въезд', $rows[0]['name']); // 31000 > 12400
        $this->assertSame(31000.0, $rows[0]['sum']);
        $this->assertSame('Электрика', $rows[1]['name']);
    }

    public function test_list_for_month_has_category_name_and_date_desc(): void
    {
        $this->repo->create('expense', $this->roadCat, 31000.0, '2026-08-03', 'Щебень', 'А');
        $this->repo->create('expense', $this->elecCat, 12400.0, '2026-08-28', 'Автомат', 'А');

        $ops = $this->repo->listForMonth('2026-08');
        $this->assertCount(2, $ops);
        $this->assertSame('2026-08-28', $ops[0]['entry_date']); // новее сверху
        $this->assertSame('Электрика', $ops[0]['category_name']);
    }

    public function test_update_and_delete(): void
    {
        $id = $this->repo->create('expense', $this->roadCat, 100.0, '2026-08-03', 'Черновик', 'А');
        $this->repo->updateFields($id, ['amount' => 555.0, 'note' => 'Исправлено']);
        $row = $this->repo->find($id);
        $this->assertSame(555.0, (float) $row['amount']);
        $this->assertSame('Исправлено', $row['note']);

        $this->repo->delete($id);
        $this->assertNull($this->repo->find($id));
    }
}
```

- [ ] **Step 2: Прогнать — должен упасть**

Run: `php vendor/bin/phpunit --filter CouncilLedgerRepositoryTest`
Expected: FAIL — `Class "SkazResidents\Repository\CouncilLedgerRepository" not found`.

- [ ] **Step 3: Реализовать репозиторий**

Create `residents/src/Repository/CouncilLedgerRepository.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

/**
 * Операции бюджета (приход/расход). Все агрегации/группировки по месяцам
 * считаются в PHP из одного запроса allWithCategory() — по правилу раздела
 * (никаких диалектных функций дат/агрегатов в SQL). Месяц = substr(entry_date,0,7).
 */
final class CouncilLedgerRepository
{
    private const ALLOWED = ['kind', 'category_id', 'amount', 'entry_date', 'note'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(string $kind, int $categoryId, float $amount, string $entryDate, string $note, string $author): int
    {
        $st = $this->db->prepare(
            'INSERT INTO council_ledger_entries (kind, category_id, amount, entry_date, note, author)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$kind, $categoryId, $amount, $entryDate, mb_substr($note, 0, 300), mb_substr($author, 0, 160)]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM council_ledger_entries WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** $patch — подмножество ALLOWED. */
    public function updateFields(int $id, array $patch): void
    {
        $set = [];
        $args = [];
        foreach ($patch as $key => $val) {
            if (!in_array($key, self::ALLOWED, true)) { continue; }
            if ($key === 'amount')   { $val = max(0.0, (float) $val); }
            if ($key === 'note')     { $val = mb_substr((string) $val, 0, 300); }
            $set[] = "$key = ?";
            $args[] = $val;
        }
        if (!$set) { return; }
        $args[] = $id;
        $st = $this->db->prepare('UPDATE council_ledger_entries SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($args);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM council_ledger_entries WHERE id = ?')->execute([$id]);
    }

    /**
     * Все операции с именем статьи, новые сверху. amount приведён к float.
     * @return array<int,array<string,mixed>>
     */
    public function allWithCategory(): array
    {
        $rows = $this->db->query(
            'SELECT e.id, e.kind, e.category_id, e.amount, e.entry_date, e.note, e.author, e.created_at,
                    c.name AS category_name
             FROM council_ledger_entries e
             JOIN council_ledger_categories c ON c.id = e.category_id
             ORDER BY e.entry_date DESC, e.id DESC'
        )->fetchAll();
        foreach ($rows as &$r) { $r['amount'] = (float) $r['amount']; }
        unset($r);
        return $rows;
    }

    /** Операции одного месяца (YYYY-MM), новые сверху. @return array<int,array<string,mixed>> */
    public function listForMonth(string $ym): array
    {
        return array_values(array_filter(
            $this->allWithCategory(),
            static fn($r) => substr((string) $r['entry_date'], 0, 7) === $ym
        ));
    }

    /**
     * Суммы прихода/расхода по месяцам.
     * @return array<string,array{income:float,expense:float}>
     */
    public function sumsByMonth(): array
    {
        $out = [];
        foreach ($this->allWithCategory() as $r) {
            $ym = substr((string) $r['entry_date'], 0, 7);
            if (!isset($out[$ym])) { $out[$ym] = ['income' => 0.0, 'expense' => 0.0]; }
            $out[$ym][$r['kind'] === 'income' ? 'income' : 'expense'] += (float) $r['amount'];
        }
        return $out;
    }

    /** Список месяцев с данными, новые сверху. @return array<int,string> */
    public function monthsWithData(): array
    {
        $seen = [];
        foreach ($this->allWithCategory() as $r) { // уже отсортировано date desc
            $ym = substr((string) $r['entry_date'], 0, 7);
            $seen[$ym] = true;
        }
        return array_keys($seen);
    }

    /**
     * Разбивка расходов месяца по статьям, по убыванию суммы.
     * @return array<int,array{name:string,sum:float}>
     */
    public function expenseByCategory(string $ym): array
    {
        $acc = [];
        foreach ($this->listForMonth($ym) as $r) {
            if ($r['kind'] !== 'expense') { continue; }
            $name = (string) $r['category_name'];
            $acc[$name] = ($acc[$name] ?? 0.0) + (float) $r['amount'];
        }
        $rows = [];
        foreach ($acc as $name => $sum) { $rows[] = ['name' => $name, 'sum' => $sum]; }
        usort($rows, static fn($a, $b) => $b['sum'] <=> $a['sum']);
        return $rows;
    }
}
```

- [ ] **Step 4: Прогнать — должен пройти**

Run: `php vendor/bin/phpunit --filter CouncilLedgerRepositoryTest`
Expected: PASS (5 тестов). Затем `php -l residents/src/Repository/CouncilLedgerRepository.php`.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Repository/CouncilLedgerRepository.php residents/tests/CouncilLedgerRepositoryTest.php
git commit -m "feat(ledger): репозиторий операций бюджета с помесячными агрегациями"
```

---

## Task 4: LedgerReport (сборка модели отчёта, TDD)

**Files:**
- Create: `residents/src/Service/LedgerReport.php`
- Test: `residents/tests/LedgerReportTest.php`

- [ ] **Step 1: Написать падающий тест**

Create `residents/tests/LedgerReportTest.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\LedgerReport;
use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\CouncilCategoryRepository;
use SkazResidents\Repository\ImageRepository;

final class LedgerReportTest extends TestCase
{
    private CouncilLedgerRepository $ledger;
    private int $incomeCat;
    private int $roadCat;
    private int $elecCat;

    protected function setUp(): void
    {
        make_test_db();
        $cats = new CouncilCategoryRepository();
        $this->incomeCat = $cats->create('income', 'Из Фонда общего дома');
        $this->roadCat   = $cats->create('expense', 'Дороги и въезд');
        $this->elecCat   = $cats->create('expense', 'Электрика');
        $this->ledger = new CouncilLedgerRepository();
    }

    private function report(): LedgerReport
    {
        return new LedgerReport($this->ledger, new ImageRepository());
    }

    public function test_month_balance_can_be_negative(): void
    {
        // Август: собрано 42000, потрачено 62400 → остаток −20400
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'Взносы', 'А');
        $this->ledger->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'Щиток',  'А');

        $r = $this->report()->build();
        $aug = $r['months'][0];
        $this->assertSame('2026-08', $aug['ym']);
        $this->assertSame(42000.0, $aug['income']);
        $this->assertSame(62400.0, $aug['expense']);
        $this->assertSame(-20400.0, $aug['balance']);
    }

    public function test_totals_all_time(): void
    {
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-07-05', 'a', 'А');
        $this->ledger->create('expense', $this->roadCat,   10000.0, '2026-07-15', 'b', 'А');
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'c', 'А');

        $r = $this->report()->build();
        $this->assertSame(84000.0, $r['totalIncome']);
        $this->assertSame(10000.0, $r['totalExpense']);
        $this->assertSame(74000.0, $r['totalBalance']);
    }

    public function test_default_selected_month_is_latest_with_breakdown_pct(): void
    {
        $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        $this->ledger->create('expense', $this->elecCat, 10000.0, '2026-08-28', 'свет',   'А');

        $r = $this->report()->build();
        $this->assertSame('2026-08', $r['selectedYm']);
        $this->assertSame('Август 2026', $r['selectedLabel']);
        // 30000 из 40000 = 75%
        $this->assertSame('Дороги и въезд', $r['breakdown'][0]['name']);
        $this->assertSame(75, $r['breakdown'][0]['pct']);
    }

    public function test_operations_carry_receipt_flag(): void
    {
        $exp = $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        $this->ledger->create('income', $this->incomeCat, 42000.0, '2026-08-05', 'взносы', 'А');
        (new ImageRepository())->add('expense', $exp, 'receipt.jpg', 0);

        $r = $this->report()->build('2026-08');
        $byId = [];
        foreach ($r['operations'] as $op) { $byId[$op['id']] = $op; }
        $this->assertTrue($byId[$exp]['hasReceipt']);
    }

    public function test_empty_state_does_not_crash(): void
    {
        $r = $this->report()->build();
        $this->assertSame([], $r['months']);
        $this->assertNull($r['selectedYm']);
        $this->assertSame([], $r['operations']);
        $this->assertSame(0.0, $r['totalBalance']);
    }
}
```

- [ ] **Step 2: Прогнать — должен упасть**

Run: `php vendor/bin/phpunit --filter LedgerReportTest`
Expected: FAIL — `Class "SkazResidents\Service\LedgerReport" not found`.

- [ ] **Step 3: Реализовать сервис**

Create `residents/src/Service/LedgerReport.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\CouncilLedgerRepository;
use SkazResidents\Repository\ImageRepository;

/**
 * Собирает модель наглядного отчёта «Бюджет Общего дома» из репозиториев —
 * единый источник правды для страницы совета и страницы жителей (цифры
 * идентичны). Ничего не пишет, только читает.
 *
 * build(?$selectedYm) → [
 *   'months'       => [ ['ym','label','income','expense','balance'], ... ] (новые сверху),
 *   'totalIncome','totalExpense','totalBalance' => float (за всё время),
 *   'selectedYm'   => 'YYYY-MM'|null, 'selectedLabel' => string,
 *   'breakdown'    => [ ['name','sum','pct'], ... ] (расходы месяца, по убыванию),
 *   'operations'   => [ ['id','kind','category','amount','entry_date','note','hasReceipt'], ... ],
 * ]
 */
final class LedgerReport
{
    private const MONTHS_RU = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    public function __construct(
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function build(?string $selectedYm = null): array
    {
        $sums       = $this->ledger->sumsByMonth();
        $monthsList = $this->ledger->monthsWithData(); // новые сверху

        $months = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        foreach ($monthsList as $ym) {
            $income  = $sums[$ym]['income']  ?? 0.0;
            $expense = $sums[$ym]['expense'] ?? 0.0;
            $months[] = [
                'ym'      => $ym,
                'label'   => $this->label($ym),
                'income'  => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
            ];
            $totalIncome  += $income;
            $totalExpense += $expense;
        }

        // Выбранный месяц: переданный (если есть данные) либо последний.
        if ($selectedYm === null || !in_array($selectedYm, $monthsList, true)) {
            $selectedYm = $monthsList[0] ?? null;
        }

        $breakdown = [];
        $operations = [];
        if ($selectedYm !== null) {
            $rows = $this->ledger->expenseByCategory($selectedYm);
            $expTotal = array_sum(array_map(static fn($r) => $r['sum'], $rows));
            foreach ($rows as $r) {
                $breakdown[] = [
                    'name' => $r['name'],
                    'sum'  => $r['sum'],
                    'pct'  => $expTotal > 0 ? (int) round($r['sum'] / $expTotal * 100) : 0,
                ];
            }
            foreach ($this->ledger->listForMonth($selectedYm) as $op) {
                $hasReceipt = $op['kind'] === 'expense'
                    && count($this->images->listFor('expense', (int) $op['id'])) > 0;
                $operations[] = [
                    'id'         => (int) $op['id'],
                    'kind'       => $op['kind'],
                    'category'   => (string) $op['category_name'],
                    'amount'     => (float) $op['amount'],
                    'entry_date' => (string) $op['entry_date'],
                    'note'       => (string) $op['note'],
                    'hasReceipt' => $hasReceipt,
                ];
            }
        }

        return [
            'months'        => $months,
            'totalIncome'   => $totalIncome,
            'totalExpense'  => $totalExpense,
            'totalBalance'  => $totalIncome - $totalExpense,
            'selectedYm'    => $selectedYm,
            'selectedLabel' => $selectedYm !== null ? $this->label($selectedYm) : '',
            'breakdown'     => $breakdown,
            'operations'    => $operations,
        ];
    }

    private function label(string $ym): string
    {
        [$y, $m] = array_pad(explode('-', $ym), 2, '0');
        $name = self::MONTHS_RU[(int) $m] ?? $ym;
        return $name . ' ' . $y;
    }
}
```

- [ ] **Step 4: Прогнать — должен пройти**

Run: `php vendor/bin/phpunit --filter LedgerReportTest`
Expected: PASS (5 тестов). Затем `php -l residents/src/Service/LedgerReport.php`.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Service/LedgerReport.php residents/tests/LedgerReportTest.php
git commit -m "feat(ledger): сервис сборки помесячного отчёта бюджета"
```

---

## Task 5: Партиал наглядной части + CSS

Общая наглядная часть (плитки итогов, помесячная таблица, полоски по статьям, лента операций). Используется и советом, и жителями. Редактирование операций (edit/delete) показывается только при `$editable === true` — реализуется в Task 6 (совет передаёт `$editable=true` и `$expenseCats` для селекта правки; жители — `$editable=false`).

**Files:**
- Create: `residents/src/templates/partials/ledger_report.php`
- Modify: `residents/public/assets/residents.css` (в конец)

- [ ] **Step 1: Дописать CSS в конец `residents/public/assets/residents.css`**

```css
/* ==== Бюджет Общего дома ==== */
.ledger-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin: 1.2rem 0; }
@media (max-width: 640px) { .ledger-tiles { grid-template-columns: 1fr; } }
.ledger-tile { background: #fff; border: 1px solid #eee; border-radius: 10px; padding: 1rem 1.2rem; }
.ledger-tile .label { font-size: .8rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.ledger-tile .val { font-family: var(--font-head); font-size: 1.6rem; font-weight: 700; margin-top: .2rem; }
.ledger-tile--in .val { color: var(--green); }
.ledger-tile--out .val { color: var(--ochre); }
.ledger-tile--bal { background: #eef4ef; border-color: #d7e8dd; }
.ledger-tile--bal .val { color: var(--green); }
.ledger-tile--bal.neg .val { color: #c0392b; }

.months { width: 100%; border-collapse: collapse; }
.months th, .months td { text-align: right; padding: .55rem .5rem; border-bottom: 1px solid #f0efe9; white-space: nowrap; }
.months th { font-size: .76rem; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
.months th:first-child, .months td:first-child { text-align: left; }
.months tr:last-child td { border-bottom: 0; }
.months tfoot td { border-top: 2px solid #e5e5de; font-weight: 700; }
.m-in { color: var(--green); }
.m-out { color: var(--ochre); }
.m-bal { font-weight: 700; }
.m-bal.pos { color: var(--green); }
.m-bal.neg { color: #c0392b; }
.m-name a { display: block; font-size: .78rem; font-weight: 400; text-decoration: none; }
.m-name a.active { color: var(--ink); text-decoration: underline; }

.bars { list-style: none; margin: .4rem 0 0; padding: 0; }
.bar { padding: .55rem 0; border-bottom: 1px solid #f0efe9; }
.bar:last-child { border-bottom: 0; }
.bar-top { display: flex; justify-content: space-between; gap: 1rem; align-items: baseline; margin-bottom: .3rem; }
.bar-name { font-weight: 700; }
.bar-sum { white-space: nowrap; }
.bar-pct { color: var(--muted); font-size: .85rem; margin-left: .4rem; }
.bar-track { height: 12px; background: #f2f2ee; border-radius: 999px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 999px; background: var(--green); }

.ops { list-style: none; margin: .4rem 0 0; padding: 0; }
.op-row { display: grid; grid-template-columns: auto 1fr auto; gap: .7rem; align-items: baseline; padding: .5rem 0; border-bottom: 1px solid #f0efe9; }
.op-row:last-child { border-bottom: 0; }
.op-date { color: var(--muted); font-size: .85rem; white-space: nowrap; }
.op-cat { font-size: .72rem; padding: .1rem .55rem; border-radius: 999px; background: #eef4ef; color: var(--green); white-space: nowrap; margin-right: .4rem; }
.op-sum { font-weight: 700; white-space: nowrap; }
.op-sum--out { color: var(--ochre); }
.op-sum--in { color: var(--green); }
.op-doc { font-size: .8rem; color: var(--green); margin-left: .4rem; }
.ledger-hint { background: #fff6e5; border: 1px solid #f0e2c2; border-radius: 8px; padding: .6rem .9rem; font-size: .88rem; color: #7a5a1e; margin: .2rem 0 1rem; }
.ledger-empty { padding: 1.5rem 0; color: var(--muted); }
.ledger-forms { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1.4rem 0; }
@media (max-width: 640px) { .ledger-forms { grid-template-columns: 1fr; } }
.ledger-op-edit { border-top: 1px dashed #e6e5df; margin-top: .4rem; padding-top: .5rem; }
.ledger-op-edit summary { cursor: pointer; font-size: .82rem; color: var(--muted); list-style: none; }
.ledger-op-edit summary::-webkit-details-marker { display: none; }
```

- [ ] **Step 2: Создать партиал `residents/src/templates/partials/ledger_report.php`**

Ожидаемые переменные в области видимости: `$report` (из `LedgerReport::build`), `$editable` (bool), `$basePath` (строка: `/sovet/buhgalteriya` или `/poselenie/byudzhet` — для ссылок выбора месяца), `$uploadsUrl` (строка), а при `$editable` — `$expenseCats`, `$incomeCats` (списки статей для формы правки) и `\SkazResidents\Csrf`.

```php
<?php
use SkazResidents\View;
use SkazResidents\Csrf;
/** @var array $report */ /** @var bool $editable */ /** @var string $basePath */ /** @var string $uploadsUrl */
$fmt = static fn(float $n): string => number_format(abs($n), 0, '.', ' ') . ' ₽';
$sign = static fn(float $n): string => ($n < 0 ? '−' : '+') . number_format(abs($n), 0, '.', ' ') . ' ₽';
$editCats = $editable ? ['income' => $incomeCats ?? [], 'expense' => $expenseCats ?? []] : ['income' => [], 'expense' => []];
?>
<?php if (!$report['months']): ?>
    <p class="ledger-empty">Пока нет ни одной операции. Данные появятся здесь, как только совет внесёт первые приходы и расходы.</p>
<?php else: ?>

<div class="ledger-tiles">
    <div class="ledger-tile ledger-tile--in"><div class="label">Собрано, <?= View::e($report['selectedLabel']) ?></div><div class="val"><?= View::e($fmt($report['monthIncome'] ?? 0.0)) ?></div></div>
    <div class="ledger-tile ledger-tile--out"><div class="label">Потрачено, <?= View::e($report['selectedLabel']) ?></div><div class="val"><?= View::e($fmt($report['monthExpense'] ?? 0.0)) ?></div></div>
    <?php $mb = $report['monthBalance'] ?? 0.0; ?>
    <div class="ledger-tile ledger-tile--bal <?= $mb < 0 ? 'neg' : '' ?>"><div class="label">Остаток месяца</div><div class="val"><?= View::e($sign($mb)) ?></div></div>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .4rem">Помесячно</h2>
    <p class="ledger-hint">Доход поступает ежемесячно. В отдельные месяцы крупные траты превышают доход — остаток месяца уходит в минус, это нормально.</p>
    <table class="months">
        <thead><tr><th>Месяц</th><th>Собрано</th><th>Потрачено</th><th>Остаток месяца</th></tr></thead>
        <tbody>
            <?php foreach ($report['months'] as $m): ?>
                <tr>
                    <td class="m-name">
                        <a class="<?= $m['ym'] === $report['selectedYm'] ? 'active' : '' ?>" href="<?= View::e($basePath) ?>?mesyac=<?= View::e($m['ym']) ?>"><?= View::e($m['label']) ?></a>
                    </td>
                    <td class="m-in"><?= View::e($fmt($m['income'])) ?></td>
                    <td class="m-out"><?= View::e($fmt($m['expense'])) ?></td>
                    <td class="m-bal <?= $m['balance'] < 0 ? 'neg' : 'pos' ?>"><?= View::e($sign($m['balance'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Итого за всё время</td>
                <td class="m-in"><?= View::e($fmt($report['totalIncome'])) ?></td>
                <td class="m-out"><?= View::e($fmt($report['totalExpense'])) ?></td>
                <td class="m-bal <?= $report['totalBalance'] < 0 ? 'neg' : 'pos' ?>"><?= View::e($sign($report['totalBalance'])) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .2rem">Расходы по статьям — <?= View::e($report['selectedLabel']) ?></h2>
    <?php if (!$report['breakdown']): ?>
        <p class="res-meta">В этом месяце расходов не было.</p>
    <?php else: ?>
        <ul class="bars">
            <?php foreach ($report['breakdown'] as $b): ?>
                <li class="bar">
                    <div class="bar-top"><span class="bar-name"><?= View::e($b['name']) ?></span><span class="bar-sum"><?= View::e($fmt($b['sum'])) ?><span class="bar-pct"><?= (int) $b['pct'] ?>%</span></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= (int) $b['pct'] ?>%"></div></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="res-card">
    <h2 style="font-size:1.1rem;margin:0 0 .2rem">Операции — <?= View::e($report['selectedLabel']) ?></h2>
    <?php if (!$report['operations']): ?>
        <p class="res-meta">Операций за этот месяц нет.</p>
    <?php else: ?>
        <ul class="ops">
            <?php foreach ($report['operations'] as $op): ?>
                <?php $isOut = $op['kind'] === 'expense'; $d = (string) $op['entry_date']; ?>
                <li>
                    <div class="op-row">
                        <span class="op-date"><?= View::e(substr($d, 8, 2) . '.' . substr($d, 5, 2)) ?></span>
                        <span><span class="op-cat"><?= View::e($op['category']) ?></span><?= View::e($op['note']) ?></span>
                        <span class="op-sum <?= $isOut ? 'op-sum--out' : 'op-sum--in' ?>">
                            <?= $isOut ? '−' : '+' ?><?= View::e($fmt($op['amount'])) ?>
                            <?php if ($op['hasReceipt']): ?><a class="op-doc" href="<?= View::e($uploadsUrl) ?>/<?= View::e($op['receiptPath'] ?? '') ?>" target="_blank" rel="noopener">чек</a><?php endif; ?>
                        </span>
                    </div>
                    <?php if ($editable): ?>
                        <details class="ledger-op-edit">
                            <summary>Изменить / удалить</summary>
                            <form method="post" action="<?= View::e($basePath) ?>/operaciya/<?= (int) $op['id'] ?>/obnovit" class="res-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm']) ?>">
                                <label>Статья
                                    <select name="category_id">
                                        <?php foreach ($editCats[$op['kind']] as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>" <?= (string) $c['name'] === $op['category'] ? 'selected' : '' ?>><?= View::e($c['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Сумма, ₽ <input type="text" name="amount" value="<?= View::e(number_format($op['amount'], 2, '.', '')) ?>"></label>
                                <label>Дата <input type="date" name="entry_date" value="<?= View::e($d) ?>"></label>
                                <label>Описание <input type="text" name="note" value="<?= View::e($op['note']) ?>" maxlength="300"></label>
                                <button type="submit" class="res-btn">Сохранить</button>
                            </form>
                            <form method="post" action="<?= View::e($basePath) ?>/operaciya/<?= (int) $op['id'] ?>/udalit" onsubmit="return confirm('Удалить операцию?')" style="margin-top:.5rem">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm']) ?>">
                                <button type="submit" class="res-link-btn sovet-danger">Удалить операцию</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php endif; ?>
```

> **Замечание по данным:** партиал ждёт от `$report` дополнительно `monthIncome`/`monthExpense`/`monthBalance` (итоги выбранного месяца для плиток) и у операций поле `receiptPath` (имя файла чека). Эти поля добавляются в `LedgerReport::build` в Task 6, Step 1 — сделать ДО первого рендера партиала.

- [ ] **Step 3: Проверить синтаксис партиала**

Run: `php -l residents/src/templates/partials/ledger_report.php`
Expected: «No syntax errors detected».

- [ ] **Step 4: Коммит**

```bash
git add residents/src/templates/partials/ledger_report.php residents/public/assets/residents.css
git commit -m "feat(ledger): общий партиал отчёта и стили бюджета"
```

---

## Task 6: Дополнить LedgerReport полями для плиток и чеков (TDD)

Партиалу нужны итоги выбранного месяца (`monthIncome/monthExpense/monthBalance`) и путь к файлу чека у операции (`receiptPath`). Дополняем сервис и тест.

**Files:**
- Modify: `residents/src/Service/LedgerReport.php`
- Modify: `residents/tests/LedgerReportTest.php`

- [ ] **Step 1: Добавить проверки в тест**

В `residents/tests/LedgerReportTest.php` добавить два теста в класс:

```php
    public function test_selected_month_tiles(): void
    {
        $this->ledger->create('income',  $this->incomeCat, 42000.0, '2026-08-05', 'взносы', 'А');
        $this->ledger->create('expense', $this->elecCat,   62400.0, '2026-08-20', 'щиток',  'А');

        $r = $this->report()->build('2026-08');
        $this->assertSame(42000.0, $r['monthIncome']);
        $this->assertSame(62400.0, $r['monthExpense']);
        $this->assertSame(-20400.0, $r['monthBalance']);
    }

    public function test_operation_carries_receipt_path(): void
    {
        $exp = $this->ledger->create('expense', $this->roadCat, 30000.0, '2026-08-03', 'дорога', 'А');
        (new ImageRepository())->add('expense', $exp, 'abc123.jpg', 0);

        $r = $this->report()->build('2026-08');
        $op = $r['operations'][0];
        $this->assertTrue($op['hasReceipt']);
        $this->assertSame('abc123.jpg', $op['receiptPath']);
    }
```

- [ ] **Step 2: Прогнать — новые тесты падают**

Run: `php vendor/bin/phpunit --filter LedgerReportTest`
Expected: FAIL на `test_selected_month_tiles` / `test_operation_carries_receipt_path` (undefined key `monthIncome` / `receiptPath`).

- [ ] **Step 3: Дополнить сервис**

В `residents/src/Service/LedgerReport.php` внутри `build()`:

(3a) Заменить блок формирования `$operations` — добавить `receiptPath`:

```php
            foreach ($this->ledger->listForMonth($selectedYm) as $op) {
                $receipt = $op['kind'] === 'expense'
                    ? $this->images->listFor('expense', (int) $op['id'])
                    : [];
                $operations[] = [
                    'id'          => (int) $op['id'],
                    'kind'        => $op['kind'],
                    'category'    => (string) $op['category_name'],
                    'amount'      => (float) $op['amount'],
                    'entry_date'  => (string) $op['entry_date'],
                    'note'        => (string) $op['note'],
                    'hasReceipt'  => count($receipt) > 0,
                    'receiptPath' => $receipt[0]['path'] ?? null,
                ];
            }
```

(3b) В финальном `return [...]` добавить три поля (итоги выбранного месяца из `$sums`):

```php
            'monthIncome'   => $selectedYm !== null ? ($sums[$selectedYm]['income']  ?? 0.0) : 0.0,
            'monthExpense'  => $selectedYm !== null ? ($sums[$selectedYm]['expense'] ?? 0.0) : 0.0,
            'monthBalance'  => $selectedYm !== null ? (($sums[$selectedYm]['income'] ?? 0.0) - ($sums[$selectedYm]['expense'] ?? 0.0)) : 0.0,
```

- [ ] **Step 4: Прогнать — всё зелёное**

Run: `php vendor/bin/phpunit --filter LedgerReportTest`
Expected: PASS (7 тестов). `php -l residents/src/Service/LedgerReport.php`.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Service/LedgerReport.php residents/tests/LedgerReportTest.php
git commit -m "feat(ledger): итоги месяца и путь к чеку в модели отчёта"
```

---

## Task 7: BudgetController + страница жителей (read-only)

**Files:**
- Create: `residents/src/Controller/BudgetController.php`
- Create: `residents/src/templates/budget/report.php`
- Modify: `residents/public/index.php` (импорт + маршрут)

- [ ] **Step 1: Создать контроллер жителей**

Create `residents/src/Controller/BudgetController.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, View};
use SkazResidents\Service\LedgerReport;

/**
 * Отчёт «Бюджет Общего дома» для жителей — read-only. Виден любому
 * авторизованному жителю (Auth::requireLogin). Данные собирает LedgerReport —
 * тот же источник, что у страницы совета, поэтому цифры совпадают.
 */
final class BudgetController
{
    public function __construct(
        private LedgerReport $report = new LedgerReport()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $ym = isset($_GET['mesyac']) ? (string) $_GET['mesyac'] : null;
        View::render('budget/report', [
            'report'     => $this->report->build($ym),
            'editable'   => false,
            'basePath'   => '/poselenie/byudzhet',
            'uploadsUrl' => rtrim((string) Config::get('uploads_url'), '/'),
        ], 'Бюджет Общего дома');
    }
}
```

- [ ] **Step 2: Создать шаблон жителей**

Create `residents/src/templates/budget/report.php`:

```php
<?php /** @var array $report */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Для жителей поселения</p>
    <h1>Бюджет Общего дома</h1>
    <p>Куда идут наши взносы — открытый помесячный отчёт Попечительского совета.</p>
</section>

<?php require __DIR__ . '/../partials/ledger_report.php'; ?>

<p class="res-meta" style="text-align:center;margin-top:1.5rem">Отчёт ведёт Попечительский совет во внутреннем портале. Вопросы по цифрам — к дежурному председателю.</p>
```

- [ ] **Step 3: Зарегистрировать маршрут в `residents/public/index.php`**

После группы `use ...Council\AdminController...` добавить импорт (рядом с прочими `use ...Controller...`):

```php
use SkazResidents\Controller\BudgetController;
```

И в секции жителей (например, сразу после блока `$public = new PublicController(); ...` или рядом с кабинетом) добавить:

```php
$budget = new BudgetController();
$router->get('/poselenie/byudzhet', [$budget, 'index']);
```

- [ ] **Step 4: Проверка синтаксиса и полный прогон тестов**

Run:
```
php -l residents/src/Controller/BudgetController.php
php -l residents/src/templates/budget/report.php
php -l residents/public/index.php
php vendor/bin/phpunit
```
Expected: все `-l` без ошибок; PHPUnit — весь набор зелёный (существующие + новые). `RouterTest` не должен падать.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Controller/BudgetController.php residents/src/templates/budget/report.php residents/public/index.php
git commit -m "feat(ledger): read-only отчёт бюджета для жителей (/poselenie/byudzhet)"
```

---

## Task 8: LedgerController + страница совета (ввод/правка операций)

**Files:**
- Create: `residents/src/Controller/Council/LedgerController.php`
- Create: `residents/src/templates/council/ledger.php`
- Modify: `residents/public/index.php` (импорт + маршруты)

- [ ] **Step 1: Создать контроллер совета**

Create `residents/src/Controller/Council/LedgerController.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Csrf, Flash, Config, Upload, View};
use SkazResidents\Service\LedgerReport;
use SkazResidents\Repository\{CouncilLedgerRepository, CouncilCategoryRepository, ImageRepository};

/**
 * Бухгалтерия совета — ввод и правка операций бюджета. Доступ: все члены совета
 * (CouncilAuth::requireLogin). Управление справочником статей — только админ
 * (см. CategoryController-методы ниже, гард requireAdmin). Отчёт рендерит тем же
 * партиалом, что и страница жителей, но с $editable=true.
 */
final class LedgerController
{
    private const KINDS = ['income', 'expense'];

    public function __construct(
        private CouncilLedgerRepository $ledger = new CouncilLedgerRepository(),
        private CouncilCategoryRepository $cats = new CouncilCategoryRepository(),
        private ImageRepository $images = new ImageRepository(),
        private LedgerReport $report = new LedgerReport()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireLogin();
        $ym = isset($_GET['mesyac']) ? (string) $_GET['mesyac'] : null;
        View::render('council/ledger', [
            'report'      => $this->report->build($ym),
            'editable'    => true,
            'basePath'    => '/sovet/buhgalteriya',
            'uploadsUrl'  => rtrim((string) Config::get('uploads_url'), '/'),
            'incomeCats'  => $this->cats->listByKind('income', true),
            'expenseCats' => $this->cats->listByKind('expense', true),
            'me'          => CouncilAuth::name(),
        ], 'Бухгалтерия', 'council/layout');
    }

    public function create(): void
    {
        $this->guard();
        $kind = in_array($_POST['kind'] ?? '', self::KINDS, true) ? $_POST['kind'] : '';
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $amount = max(0.0, (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '')));
        $date = trim($_POST['entry_date'] ?? '');
        $note = trim($_POST['note'] ?? '');

        $cat = $this->cats->find($categoryId);
        if ($kind === '' || !$cat || $cat['kind'] !== $kind) {
            Flash::set('error', 'Выберите статью, соответствующую типу операции.');
            $this->back(); return;
        }
        if ($amount <= 0) { Flash::set('error', 'Укажите сумму больше нуля.'); $this->back(); return; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { Flash::set('error', 'Укажите корректную дату.'); $this->back($date); return; }

        $id = $this->ledger->create($kind, $categoryId, $amount, $date, $note, CouncilAuth::name());
        if ($kind === 'expense') { $this->handleReceipt($id); }
        Flash::set('success', 'Операция добавлена.');
        $this->back($date);
    }

    public function update(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        $entry = $this->ledger->find($id);
        if (!$entry) { $this->back(); return; }

        $patch = [];
        if (isset($_POST['category_id'])) {
            $cat = $this->cats->find((int) $_POST['category_id']);
            if ($cat && $cat['kind'] === $entry['kind']) { $patch['category_id'] = (int) $_POST['category_id']; }
        }
        if (isset($_POST['amount']))     { $patch['amount'] = max(0.0, (float) str_replace(',', '.', (string) $_POST['amount'])); }
        if (isset($_POST['note']))       { $patch['note']   = trim($_POST['note']); }
        if (isset($_POST['entry_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['entry_date']))) {
            $patch['entry_date'] = trim($_POST['entry_date']);
        }
        $this->ledger->updateFields($id, $patch);
        Flash::set('success', 'Операция обновлена.');
        $this->back();
    }

    public function delete(array $params = []): void
    {
        $this->guard();
        $id = (int) ($params['id'] ?? 0);
        if ($this->ledger->find($id)) {
            $this->deleteReceiptFiles($id);
            $this->images->deleteFor('expense', $id);
            $this->ledger->delete($id);
            Flash::set('info', 'Операция удалена.');
        }
        $this->back();
    }

    // ---- Управление справочником статей (только админ) ----

    public function categories(): void
    {
        CouncilAuth::requireAdmin();
        View::render('council/categories', [
            'income'  => $this->cats->listByKind('income', false),
            'expense' => $this->cats->listByKind('expense', false),
        ], 'Статьи бюджета', 'council/layout');
    }

    public function addCategory(): void
    {
        $this->guardAdmin();
        $kind = in_array($_POST['kind'] ?? '', self::KINDS, true) ? $_POST['kind'] : 'expense';
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') { $this->cats->create($kind, $name); Flash::set('success', 'Статья добавлена.'); }
        else { Flash::set('error', 'Название статьи не может быть пустым.'); }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    public function renameCategory(array $params = []): void
    {
        $this->guardAdmin();
        $id = (int) ($params['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && $this->cats->find($id)) { $this->cats->rename($id, $name); Flash::set('success', 'Статья переименована.'); }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    public function toggleCategory(array $params = []): void
    {
        $this->guardAdmin();
        $id = (int) ($params['id'] ?? 0);
        $cat = $this->cats->find($id);
        if ($cat) {
            $active = (int) $cat['is_active'] === 1;
            $this->cats->setActive($id, !$active);
            Flash::set('info', $active ? 'Статья убрана из выбора (архив).' : 'Статья снова доступна.');
        }
        header('Location: /sovet/buhgalteriya/statyi');
    }

    // ---- helpers ----

    private function handleReceipt(int $entryId): void
    {
        if (empty($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return; }
        $dir = (string) Config::get('uploads_dir');
        [$name, $err] = Upload::saveImage($_FILES['receipt'], $dir);
        if ($name !== null) { $this->images->add('expense', $entryId, $name, 0); }
        elseif ($err !== null) { Flash::set('error', $err); }
    }

    private function deleteReceiptFiles(int $entryId): void
    {
        $dir = rtrim((string) Config::get('uploads_dir'), '/\\');
        foreach ($this->images->listFor('expense', $entryId) as $img) {
            @unlink($dir . '/' . basename((string) $img['path']));
        }
    }

    private function guard(): void
    {
        CouncilAuth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function guardAdmin(): void
    {
        CouncilAuth::requireAdmin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function back(string $ym = ''): void
    {
        if ($ym === '' && isset($_POST['mesyac'])) { $ym = (string) $_POST['mesyac']; }
        $ym = substr($ym, 0, 7);
        $q = preg_match('/^\d{4}-\d{2}$/', $ym) ? ('?mesyac=' . $ym) : '';
        header('Location: /sovet/buhgalteriya' . $q);
    }
}
```

- [ ] **Step 2: Создать шаблон совета `residents/src/templates/council/ledger.php`**

```php
<?php use SkazResidents\{View, Csrf}; /** @var array $incomeCats */ /** @var array $expenseCats */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Внутренний портал</p>
    <h1>Бухгалтерия Общего дома</h1>
    <p>Вносите приход и расход по статьям. Жители видят эти же цифры в разделе «Бюджет Общего дома» (только просмотр).</p>
    <p class="sovet-hero-actions">
        <a class="res-btn res-btn--ghost" href="/sovet/buhgalteriya/statyi">Статьи бюджета</a>
    </p>
</section>

<div class="ledger-forms">
    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--green)">+ Добавить приход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="income">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($incomeCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="42000"></label>
            <label>Дата <input type="date" name="entry_date"></label>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Взносы за август"></label>
            <button type="submit" class="res-btn">Добавить приход</button>
        </form>
    </details>

    <details class="res-card">
        <summary style="cursor:pointer;font-weight:700;color:var(--ochre)">− Добавить расход</summary>
        <form method="post" action="/sovet/buhgalteriya/operaciya" class="res-form" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="expense">
            <input type="hidden" name="mesyac" value="<?= View::e($report['selectedYm'] ?? '') ?>">
            <label>Статья
                <select name="category_id">
                    <?php foreach ($expenseCats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Сумма, ₽ <input type="text" name="amount" inputmode="decimal" placeholder="12400"></label>
            <label>Дата <input type="date" name="entry_date"></label>
            <label>Описание <input type="text" name="note" maxlength="300" placeholder="Замена автомата на щитке"></label>
            <label>Фото чека (необязательно) <input type="file" name="receipt" accept="image/*"></label>
            <button type="submit" class="res-btn">Добавить расход</button>
        </form>
    </details>
</div>

<?php require __DIR__ . '/../partials/ledger_report.php'; ?>
```

- [ ] **Step 3: Маршруты в `residents/public/index.php`**

Добавить импорт рядом с прочими Council-контроллерами:

```php
use SkazResidents\Controller\Council\LedgerController as CouncilLedgerController;
```

В секции `// ==== Попечительский совет ====` (после блока `$cAdmin`) добавить:

```php
$cLedger = new CouncilLedgerController();
$router->get('/sovet/buhgalteriya', [$cLedger, 'index']);
$router->post('/sovet/buhgalteriya/operaciya', [$cLedger, 'create']);
$router->post('/sovet/buhgalteriya/operaciya/{id}/obnovit', [$cLedger, 'update']);
$router->post('/sovet/buhgalteriya/operaciya/{id}/udalit', [$cLedger, 'delete']);
$router->get('/sovet/buhgalteriya/statyi', [$cLedger, 'categories']);
$router->post('/sovet/buhgalteriya/statyi/dobavit', [$cLedger, 'addCategory']);
$router->post('/sovet/buhgalteriya/statyi/{id}/pereimenovat', [$cLedger, 'renameCategory']);
$router->post('/sovet/buhgalteriya/statyi/{id}/arhiv', [$cLedger, 'toggleCategory']);
```

> **Порядок маршрутов:** статические пути (`/sovet/buhgalteriya/statyi`, `/sovet/buhgalteriya/operaciya`) регистрируются в примере выше ДО динамических `{id}`-путей? Роутер (`Router::dispatch`) идёт по порядку добавления и матчит первый подходящий regex. `/sovet/buhgalteriya/statyi` и `/sovet/buhgalteriya/operaciya/{id}/...` не пересекаются по шаблону, поэтому порядок между ними не критичен. Важно лишь, что здесь нет generic `/sovet/{id}` — конфликтов нет.

- [ ] **Step 4: Проверка синтаксиса и тесты**

Run:
```
php -l residents/src/Controller/Council/LedgerController.php
php -l residents/src/templates/council/ledger.php
php -l residents/public/index.php
php vendor/bin/phpunit
```
Expected: `-l` чисто; весь набор PHPUnit зелёный.

- [ ] **Step 5: Коммит**

```bash
git add residents/src/Controller/Council/LedgerController.php residents/src/templates/council/ledger.php residents/public/index.php
git commit -m "feat(ledger): страница совета — ввод и правка операций бюджета"
```

---

## Task 9: Шаблон управления статьями (совет-админ)

**Files:**
- Create: `residents/src/templates/council/categories.php`

- [ ] **Step 1: Создать шаблон**

Create `residents/src/templates/council/categories.php`:

```php
<?php use SkazResidents\{View, Csrf}; /** @var array $income */ /** @var array $expense */ ?>
<section class="sovet-hero" style="margin-bottom:1rem">
    <p class="sovet-eyebrow">Бухгалтерия · только админ</p>
    <h1>Статьи бюджета</h1>
    <p>Статьи прихода и расхода для учёта. Архивная статья не предлагается при вводе новых операций, но остаётся в исторических отчётах.</p>
    <p class="sovet-hero-actions"><a class="res-btn res-btn--ghost" href="/sovet/buhgalteriya">← К операциям</a></p>
</section>

<?php
$block = static function (string $title, array $rows, string $kind) {
    ?>
    <div class="res-card">
        <h2 style="font-size:1.1rem"><?= View::e($title) ?></h2>
        <table class="sovet-members">
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td>
                        <form method="post" action="/sovet/buhgalteriya/statyi/<?= (int) $c['id'] ?>/pereimenovat" class="sovet-sub-rename">
                            <?= Csrf::field() ?>
                            <input type="text" name="name" value="<?= View::e($c['name']) ?>" maxlength="160">
                            <button type="submit" class="res-btn" style="margin-top:0;padding:.35rem .9rem;font-size:.85rem">Сохранить</button>
                        </form>
                    </td>
                    <td style="text-align:right">
                        <?php if ((int) $c['is_active'] === 1): ?>
                            <span class="res-status res-status--published">активна</span>
                        <?php else: ?>
                            <span class="res-status res-status--rejected">архив</span>
                        <?php endif; ?>
                        <form method="post" action="/sovet/buhgalteriya/statyi/<?= (int) $c['id'] ?>/arhiv" style="display:inline;margin-left:.5rem">
                            <?= Csrf::field() ?>
                            <button type="submit" class="res-link-btn"><?= (int) $c['is_active'] === 1 ? 'В архив' : 'Вернуть' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="/sovet/buhgalteriya/statyi/dobavit" class="sovet-sub-add">
            <?= Csrf::field() ?>
            <input type="hidden" name="kind" value="<?= View::e($kind) ?>">
            <input type="text" name="name" placeholder="Новая статья" maxlength="160">
            <button type="submit" class="res-btn">Добавить</button>
        </form>
    </div>
    <?php
};
$block('Статьи прихода', $income, 'income');
$block('Статьи расхода', $expense, 'expense');
?>
```

- [ ] **Step 2: Проверка синтаксиса**

Run: `php -l residents/src/templates/council/categories.php`
Expected: «No syntax errors detected».

- [ ] **Step 3: Коммит**

```bash
git add residents/src/templates/council/categories.php
git commit -m "feat(ledger): управление справочником статей (совет-админ)"
```

---

## Task 10: Навигация — меню совета, главная совета, меню и кабинет жителей

**Files:**
- Modify: `residents/src/templates/council/layout.php`
- Modify: `residents/src/templates/council/home.php:23-27`
- Modify: `residents/src/templates/layout.php`
- Modify: `residents/src/templates/cabinet/index.php`

- [ ] **Step 1: Пункт «Бухгалтерия» в меню совета**

В `residents/src/templates/council/layout.php`, в блоке `<?php if (CouncilAuth::id() !== null): ?>`, добавить ссылку после «Текущие задачи»:

```php
            <a href="/sovet/zadachi">Текущие задачи</a>
            <a href="/sovet/buhgalteriya">Бухгалтерия</a>
```

- [ ] **Step 2: Ссылка «Бухгалтерия» на главной совета ведёт на новую страницу**

В `residents/src/templates/council/home.php` заменить блок ссылки на бухгалтерию (сейчас использует `$accounting['href']` — внешний Google-плейсхолдер) на внутреннюю ссылку. Заменить строки:

```php
                <li>
                    <a href="<?= View::e($accounting['href']) ?>" target="_blank" rel="noopener"><?= View::e($accounting['title']) ?></a>
                    <span class="sovet-kind">Бухгалтерия</span>
                </li>
```

на:

```php
                <li>
                    <a href="/sovet/buhgalteriya">Бюджет Общего дома — приход, расход, остатки</a>
                    <span class="sovet-kind">Бухгалтерия</span>
                </li>
```

- [ ] **Step 3: Пункт «Бюджет Общего дома» в меню жителей**

В `residents/src/templates/layout.php`, в блоке `<?php if (Auth::id() !== null): ?>` (где «Кабинет»/«Выход»), добавить ссылку перед «Кабинет»:

```php
            <a href="/poselenie/byudzhet">Бюджет</a>
            <a href="/poselenie/">Кабинет</a>
```

- [ ] **Step 4: Секция «Бюджет Общего дома» в кабинете жителя**

В `residents/src/templates/cabinet/index.php` добавить секцию (например, после секции «Совместные поездки», перед «Мои товары и услуги»):

```php
<section>
    <h2>Бюджет Общего дома</h2>
    <p class="res-meta">Открытый помесячный отчёт Попечительского совета — куда идут наши взносы: приход, расход по статьям, остаток каждого месяца.</p>
    <p><a class="res-btn" href="/poselenie/byudzhet">Смотреть бюджет</a></p>
</section>
```

- [ ] **Step 5: Проверка синтаксиса**

Run:
```
php -l residents/src/templates/council/layout.php
php -l residents/src/templates/council/home.php
php -l residents/src/templates/layout.php
php -l residents/src/templates/cabinet/index.php
```
Expected: все — «No syntax errors detected».

- [ ] **Step 6: Полный прогон тестов**

Run: `php vendor/bin/phpunit`
Expected: весь набор зелёный (старые + новые).

- [ ] **Step 7: Коммит**

```bash
git add residents/src/templates/council/layout.php residents/src/templates/council/home.php residents/src/templates/layout.php residents/src/templates/cabinet/index.php
git commit -m "feat(ledger): пункты навигации бюджета (совет + жители) и ссылка на главной совета"
```

---

## Task 11: Финальная проверка и деплой

**Files:** нет правок кода — проверка и выкладка.

- [ ] **Step 1: Весь набор тестов + линт всех новых файлов**

Run:
```
php vendor/bin/phpunit
```
Expected: 100% зелёный. Убедиться, что `SchemaTest`, `RouterTest`, все новые `*LedgerReportTest`/`Council*RepositoryTest` проходят.

- [ ] **Step 2: Локальный smoke гардов (по коду, без прод-БД)**

Проверить по коду (чек-лист, не автотест):
- `/poselenie/byudzhet` → `Auth::requireLogin()` (гость → редирект на `/poselenie/vhod`).
- `/sovet/buhgalteriya` и все POST-операции → `CouncilAuth::requireLogin()` + CSRF.
- `/sovet/buhgalteriya/statyi*` → `CouncilAuth::requireAdmin()` + CSRF.
- В партиале `ledger_report.php` формы правки/удаления рендерятся только при `$editable` (жители их не получают).

- [ ] **Step 3: Слить ветку (после ревью)**

Слить `feature/council-budget-ledger` в `main` (PR или fast-forward, по процессу репозитория) и запушить. Автодеплой статики Astro не затрагивает `/var/www/skaz-residents` — PHP-код доставляется отдельно (следующий шаг).

- [ ] **Step 4: Накатить схему БД на прод (ВРУЧНУЮ — обязательно до выкладки кода)**

На сервере (`ssh abconsult`):
```bash
mysql skazkray_residents < /var/www/skaz-residents/config/council-ledger-schema.sql
```
(Файл окажется на сервере после доставки кода — вариант: сначала доставить код (Step 5), затем накатить схему из доставленного файла, но ДО первого обращения к странице, иначе Prisma-подобный рантайм упадёт «table doesn't exist».)

Проверить: `mysql skazkray_residents -e "SELECT kind,name FROM council_ledger_categories ORDER BY kind,position;"` → 8 стартовых статей.

- [ ] **Step 5: Доставить код и перезапустить FPM**

По образцу [[skaz_kray_council_section]] / `residents/deploy/deploy.sh`:
```bash
# доставка кода (tar+scp или deploy.sh) в /var/www/skaz-residents, затем:
php8.3 /root/composer.phar dump-autoload --optimize --no-dev   # новые классы в автозагрузке
systemctl reload php8.3-fpm                                     # сброс opcache, иначе 500 на новых классах
```
nginx уже маршрутизирует `/sovet` и `/poselenie` — правок конфига НЕ требуется.

- [ ] **Step 6: Ручная проверка на боевом домене**

- Вход члена совета → `/sovet/buhgalteriya`: добавить приход и расход (расход — с фото чека); операция появилась, чек открывается по ссылке.
- Помесячная таблица показывает месяц с корректным знаком остатка; при расходе > прихода — минус красным.
- Админ совета → `/sovet/buhgalteriya/statyi`: добавить статью, переименовать, в архив/вернуть.
- Вход жителя → `/poselenie/byudzhet`: те же цифры, что у совета; форм ввода/правки НЕТ.
- Гость на `/poselenie/byudzhet` → редирект на вход; гость на `/sovet/buhgalteriya` → редирект на вход совета.

- [ ] **Step 7: Обновить память проекта**

Дописать в память `skaz_kray_council_section` (или новую) факт о модуле бюджета: таблицы `council_ledger_*`, схема накатана руками, страницы `/sovet/buhgalteriya` + `/poselenie/byudzhet`, чеки в `images` owner_type='expense'.

---

## Самопроверка плана (для автора)

**Покрытие спеки:**
- Двусторонний учёт приход/расход → Task 3 (kind), Task 8 (формы).
- Помесячный остаток с минусом → Task 4/6 (balance = income−expense, тест на отрицательный), партиал (класс `neg`).
- Управляемый справочник, приход-статьи «Из Фонда общего дома»/«Коммерческая аренда»/«Школа» → Task 1 (сид), Task 2 (CRUD), Task 9 (UI).
- Вносят все члены совета; справочник — только админ → Task 8 (гарды requireLogin / requireAdmin).
- Фото чека опционально → Task 8 (`handleReceipt`), партиал (ссылка «чек»).
- Наглядный вид (таблица + полоски + операции), без фильтра лет, учёт с июля 2026 → Task 5 партиал.
- Отчёт read-only всем авторизованным жителям → Task 7 (`Auth::requireLogin`, `$editable=false`).
- Стиль сайта, без JS → CSS Task 5, шаблоны на `<details>`+формах.
- Тесты + деплой со схемой вручную → Tasks 2/3/4/6, Task 11.

**Согласованность типов:** `LedgerReport::build()` возвращает ключи `months/totalIncome/totalExpense/totalBalance/selectedYm/selectedLabel/breakdown/operations` (+ `monthIncome/monthExpense/monthBalance` из Task 6); партиал и оба контроллера используют ровно их. Операция: `id/kind/category/amount/entry_date/note/hasReceipt/receiptPath`. Репозитории: методы совпадают между определением (Tasks 2/3) и вызовами (Task 4/6/8).
