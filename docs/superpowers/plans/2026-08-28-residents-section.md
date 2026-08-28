# Раздел для жителей поселения — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить на skaz-kray.ru раздел для жителей поселения: вход по email/паролю (аккаунт на семью), личный кабинет с дневником поместья и товарами/услугами, модерация редактором, публичные ленты «Дневники поместий» и «Ярмарка».

**Architecture:** Небольшое динамическое PHP-приложение (чистый PHP + PDO, тонкий роутер, шаблоны-партиалы) рядом со статическим сайтом Astro. Живёт в отдельном каталоге вне докрута статики (`/var/www/skaz-residents/`), поэтому автодеплой статики (`rsync --delete`) его не трогает. Своя база `skazkray_residents` в той же MariaDB. Исходники — в репозитории `skaz-kray-astro`, папка `residents/`; деплой на сервер отдельный (свой rsync/скрипт), секреты — в `config.php` вне git (как существующий `oauth/config.php`).

**Tech Stack:** PHP 8.3, PDO/MySQL (MariaDB), Composer (автозагрузка PSR-4 + PHPUnit в dev), нативные PHP-сессии, GD для обработки изображений, SMTP для писем. Публичные страницы рендерит PHP в дизайн-токенах сайта (зелёный `#008757`, охристый `#c98a3a`, PT Serif / PT Sans).

---

## Предусловия (среда разработки)

- **PHP 8.3 CLI** и **Composer** доступны в среде, где выполняются шаги «Run». На Windows-машине их сейчас нет — варианты: (а) поставить PHP 8.3 + Composer локально; (б) выполнять команды тестов на сервере (`ssh abconsult`, там php8.3 есть) над синхронизированной копией кода. Плейсхолдер `php` в командах = PHP 8.3 CLI.
- **PHPUnit-тесты** используют SQLite in-memory (расширение `pdo_sqlite`, входит в стандартную сборку PHP) — отдельная БД для тестов не нужна.
- **Ручная проверка в браузере** (финальная фаза) требует развёрнутого приложения — либо на сервере (`new.skaz-kray.ru`/прод), либо через `php -S` локально с локальной MariaDB. Тест-раннера для интеграционных потоков в проекте нет — это норма проекта.
- Все команды выполняются из каталога `residents/` репозитория, если не указано иное.

---

## Структура файлов

```
residents/
  composer.json                 # PSR-4 автозагрузка SkazResidents\ -> src/, phpunit (dev)
  phpunit.xml                   # конфиг тестов
  config/
    config.example.php          # шаблон конфига (в git); реальный config.php — вне git
    schema.sql                  # DDL для MariaDB
  public/                       # web root (nginx root указывает сюда)
    index.php                   # фронт-контроллер
    assets/residents.css        # стили в токенах сайта
  src/
    bootstrap.php               # загрузка конфига, автозагрузка, старт сессии
    Config.php                  # доступ к конфигу
    Database.php                # PDO-фабрика (+ инъекция для тестов)
    Csrf.php                    # CSRF-токен
    Validator.php               # валидация полей
    Auth.php                    # пароли, сессия входа, гварды доступа
    Mailer.php                  # отправка писем по SMTP
    Upload.php                  # приём/пересохранение изображений
    Router.php                  # мини-роутер
    View.php                    # рендер шаблонов
    Flash.php                   # одноразовые сообщения через сессию
    Repository/
      FamilyRepository.php
      DiaryRepository.php
      ProductRepository.php
      ImageRepository.php
    Controller/
      AuthController.php        # регистрация, вход, выход, сброс пароля
      CabinetController.php     # кабинет: обзор
      DiaryController.php       # CRUD дневника
      ProductController.php     # CRUD товаров
      ModerationController.php  # панель редактора
      PublicController.php      # публичные ленты
    templates/
      layout.php                # общий каркас (шапка/подвал в токенах сайта)
      partials/                 # формы, карточки, флеш
      auth/  cabinet/  diary/  product/  moderation/  public/
  tests/
    bootstrap.php               # автозагрузка + SQLite-схема для тестов
    schema.sqlite.sql           # портируемая схема для тестов
    CsrfTest.php  ValidatorTest.php  AuthTest.php
    FamilyRepositoryTest.php  DiaryRepositoryTest.php  ProductRepositoryTest.php
  deploy/
    nginx-residents.conf.example
    deploy.sh                   # rsync кода на сервер (без config.php/uploads)
    README.md                   # runbook установки на сервере
```

Изменения в статическом сайте (Astro): пункты меню в шапке (`src/components/Header.astro` — точное имя проверить) на «Дневники поместий», «Ярмарка», «Кабинет жителя».

---

## Фаза 0 — Каркас, конфиг, БД

### Task 1: Скелет проекта + Composer + первый зелёный тест

**Files:**
- Create: `residents/composer.json`
- Create: `residents/phpunit.xml`
- Create: `residents/tests/bootstrap.php`
- Create: `residents/tests/SanityTest.php`
- Create: `residents/.gitignore`

- [ ] **Step 1: composer.json**

```json
{
    "name": "skaz-kray/residents",
    "description": "Раздел для жителей поселения skaz-kray.ru",
    "type": "project",
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^11"
    },
    "autoload": {
        "psr-4": { "SkazResidents\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "SkazResidents\\Tests\\": "tests/" }
    },
    "config": { "optimize-autoloader": true }
}
```

- [ ] **Step 2: phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="residents">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: tests/bootstrap.php** (composer autoload; сессия-заглушка для тестов)

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
// Тестам нужен $_SESSION как обычный массив (без реальной сессии)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $GLOBALS['_SESSION'] = $_SESSION ?? [];
}
```

- [ ] **Step 4: .gitignore**

```
/vendor/
/.phpunit.cache/
/config/config.php
/public/uploads/
```

- [ ] **Step 5: tests/SanityTest.php**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

final class SanityTest extends TestCase
{
    public function test_php_version_is_8_3_or_newer(): void
    {
        $this->assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='));
    }
}
```

- [ ] **Step 6: Установить зависимости и запустить**

Run: `composer install && php vendor/bin/phpunit`
Expected: PASS (1 test, 1 assertion). `vendor/` создан.

- [ ] **Step 7: Commit**

```bash
git add residents/composer.json residents/composer.lock residents/phpunit.xml residents/tests residents/.gitignore
git commit -m "chore(residents): каркас PHP-приложения + phpunit"
```

---

### Task 2: Конфиг и подключение к БД

**Files:**
- Create: `residents/config/config.example.php`
- Create: `residents/src/Config.php`
- Create: `residents/src/Database.php`
- Create: `residents/src/bootstrap.php`

- [ ] **Step 1: config/config.example.php** (в git; реальный `config.php` копируется из него на сервере)

```php
<?php
// Копия этого файла как config/config.php (вне git) заполняется на сервере.
return [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=skazkray_residents;charset=utf8mb4',
        'user' => 'skaz_residents',
        'pass' => 'CHANGE_ME',
    ],
    'smtp' => [
        'host'      => 'smtp.skaz-kray.ru',
        'port'      => 465,
        'secure'    => 'ssl',            // ssl (465) или tls (587)
        'user'      => 'noreply@skaz-kray.ru',
        'pass'      => 'CHANGE_ME',
        'from'      => 'noreply@skaz-kray.ru',
        'from_name' => 'Сказочный Край',
    ],
    'base_url'     => 'https://skaz-kray.ru',
    'uploads_dir'  => __DIR__ . '/../public/uploads',   // куда пишем файлы
    'uploads_url'  => '/poselenie/uploads',             // как отдаём (nginx)
    'session_name' => 'skazres',
    'reset_ttl'    => 3600,                              // срок жизни токена сброса, сек
    'login_throttle' => ['max' => 5, 'window' => 900],  // 5 попыток за 15 мин
];
```

- [ ] **Step 2: src/Config.php**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        self::$data = require $path;
    }

    /** Внедрение конфига в тестах. */
    public static function set(array $data): void
    {
        self::$data = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }
}
```

- [ ] **Step 3: src/Database.php**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Подмена соединения (тесты — SQLite in-memory). */
    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Database not connected');
        }
        return self::$pdo;
    }
}
```

- [ ] **Step 4: src/bootstrap.php** (подключается фронт-контроллером)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;

Config::load(__DIR__ . '/../config/config.php');

$session = Config::get('session_name', 'skazres');
session_name($session);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/poselenie',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

Database::connect(Config::get('db'));
```

- [ ] **Step 5: Проверка синтаксиса**

Run: `php -l src/Database.php && php -l src/Config.php && php -l src/bootstrap.php`
Expected: `No syntax errors detected` для каждого.

- [ ] **Step 6: Commit**

```bash
git add residents/config/config.example.php residents/src/Config.php residents/src/Database.php residents/src/bootstrap.php
git commit -m "feat(residents): конфиг и подключение к БД (PDO)"
```

---

### Task 3: Схема БД (MariaDB) + портируемая схема для тестов

**Files:**
- Create: `residents/config/schema.sql`
- Create: `residents/tests/schema.sqlite.sql`

- [ ] **Step 1: config/schema.sql** (MariaDB)

```sql
-- База создаётся отдельно: CREATE DATABASE skazkray_residents CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE families (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(160) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending|active|blocked
    role          VARCHAR(16)  NOT NULL DEFAULT 'resident',  -- resident|editor
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_entries (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    body          MEDIUMTEXT   NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending|published|rejected
    reject_reason VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at  DATETIME     NULL,
    CONSTRAINT fk_diary_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_diary_status_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   MEDIUMTEXT   NOT NULL,
    price         VARCHAR(80)  NULL,                          -- свободный текст; NULL = по договорённости
    contact       VARCHAR(200) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at  DATETIME     NULL,
    CONSTRAINT fk_product_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_product_status_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type VARCHAR(16)  NOT NULL,                          -- entry|product
    owner_id   INT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_images_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    token      CHAR(64)     PRIMARY KEY,
    family_id  INT UNSIGNED NOT NULL,
    expires_at DATETIME     NOT NULL,
    CONSTRAINT fk_reset_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: tests/schema.sqlite.sql** (та же логика, синтаксис SQLite; репозитории пишут таймстемпы явно, поэтому `ON UPDATE` не нужен)

```sql
CREATE TABLE families (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    role TEXT NOT NULL DEFAULT 'resident',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TEXT
);
CREATE TABLE diary_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    reject_reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT
);
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    price TEXT,
    contact TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    reject_reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT
);
CREATE TABLE images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_type TEXT NOT NULL,
    owner_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE password_resets (
    token TEXT PRIMARY KEY,
    family_id INTEGER NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

- [ ] **Step 3: Обновить tests/bootstrap.php — хелпер тестовой БД**

Дописать в конец `tests/bootstrap.php`:

```php
use SkazResidents\Database;

/** Свежая SQLite in-memory БД со схемой — вызывается в setUp() тестов. */
function make_test_db(): \PDO
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $sql = file_get_contents(__DIR__ . '/schema.sqlite.sql');
    $pdo->exec($sql);
    Database::set($pdo);
    return $pdo;
}
```

- [ ] **Step 4: Тест — схема грузится без ошибок**

Create `residents/tests/SchemaTest.php`:

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function test_sqlite_schema_creates_all_tables(): void
    {
        $pdo = make_test_db();
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(
            ['diary_entries', 'families', 'images', 'login_attempts', 'password_resets', 'products'],
            $names
        );
    }
}
```

- [ ] **Step 5: Запустить**

Run: `php vendor/bin/phpunit`
Expected: PASS (все тесты зелёные, включая SchemaTest).

- [ ] **Step 6: Commit**

```bash
git add residents/config/schema.sql residents/tests/schema.sqlite.sql residents/tests/bootstrap.php residents/tests/SchemaTest.php
git commit -m "feat(residents): схема БД (MariaDB) + тестовая схема SQLite"
```

---

## Фаза 1 — Примитивы безопасности (TDD)

### Task 4: CSRF-токен

**Files:**
- Create: `residents/src/Csrf.php`
- Test: `residents/tests/CsrfTest.php`

- [ ] **Step 1: Написать падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Csrf;

final class CsrfTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function test_token_is_stable_within_session(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a)); // 32 байта hex
    }

    public function test_check_accepts_valid_and_rejects_invalid(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::check($t));
        $this->assertFalse(Csrf::check('nope'));
        $this->assertFalse(Csrf::check(null));
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php vendor/bin/phpunit --filter CsrfTest`
Expected: FAIL — класс `SkazResidents\Csrf` не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    /** HTML-поле для форм. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }
}
```

- [ ] **Step 4: Запустить — зелёный**

Run: `php vendor/bin/phpunit --filter CsrfTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Csrf.php residents/tests/CsrfTest.php
git commit -m "feat(residents): CSRF-токен"
```

---

### Task 5: Валидатор полей

**Files:**
- Create: `residents/src/Validator.php`
- Test: `residents/tests/ValidatorTest.php`

- [ ] **Step 1: Падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Validator;

final class ValidatorTest extends TestCase
{
    public function test_email(): void
    {
        $this->assertTrue(Validator::email('a@b.ru'));
        $this->assertFalse(Validator::email('нет'));
    }

    public function test_required(): void
    {
        $this->assertTrue(Validator::required('x'));
        $this->assertFalse(Validator::required('   '));
    }

    public function test_length_counts_multibyte(): void
    {
        $this->assertTrue(Validator::length('привет', 3, 10));
        $this->assertFalse(Validator::length('да', 3, 10));
    }

    public function test_password_min_8(): void
    {
        $this->assertTrue(Validator::password('12345678'));
        $this->assertFalse(Validator::password('1234567'));
    }

    public function test_image_mime(): void
    {
        $this->assertTrue(Validator::imageMime('image/jpeg'));
        $this->assertTrue(Validator::imageMime('image/png'));
        $this->assertTrue(Validator::imageMime('image/webp'));
        $this->assertFalse(Validator::imageMime('application/pdf'));
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php vendor/bin/phpunit --filter ValidatorTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Validator
{
    public static function email(string $v): bool
    {
        return (bool) filter_var(trim($v), FILTER_VALIDATE_EMAIL);
    }

    public static function required(string $v): bool
    {
        return trim($v) !== '';
    }

    public static function length(string $v, int $min, int $max): bool
    {
        $n = mb_strlen(trim($v));
        return $n >= $min && $n <= $max;
    }

    public static function password(string $v): bool
    {
        return mb_strlen($v) >= 8;
    }

    public static function imageMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter ValidatorTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Validator.php residents/tests/ValidatorTest.php
git commit -m "feat(residents): валидатор полей"
```

---

### Task 6: Auth — пароли и гварды

**Files:**
- Create: `residents/src/Auth.php`
- Test: `residents/tests/AuthTest.php`

- [ ] **Step 1: Падающий тест** (тестируем чистую логику: хеш/проверка и чтение сессии)

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Auth;

final class AuthTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function test_hash_and_verify(): void
    {
        $h = Auth::hash('секрет123');
        $this->assertNotSame('секрет123', $h);
        $this->assertTrue(Auth::verify('секрет123', $h));
        $this->assertFalse(Auth::verify('другой', $h));
    }

    public function test_session_state(): void
    {
        $this->assertNull(Auth::id());
        $_SESSION['family_id'] = 7;
        $_SESSION['role'] = 'editor';
        $this->assertSame(7, Auth::id());
        $this->assertTrue(Auth::isEditor());
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter AuthTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Auth
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function login(array $family): void
    {
        session_regenerate_id(true);
        $_SESSION['family_id'] = (int) $family['id'];
        $_SESSION['role']      = $family['role'];
        $_SESSION['family_name'] = $family['name'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function id(): ?int
    {
        return isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;
    }

    public static function isEditor(): bool
    {
        return ($_SESSION['role'] ?? '') === 'editor';
    }

    public static function requireLogin(): void
    {
        if (self::id() === null) {
            header('Location: /poselenie/vhod');
            exit;
        }
    }

    public static function requireEditor(): void
    {
        self::requireLogin();
        if (!self::isEditor()) {
            http_response_code(403);
            exit('Доступ только для редактора поселения.');
        }
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter AuthTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Auth.php residents/tests/AuthTest.php
git commit -m "feat(residents): Auth — пароли, сессия, гварды"
```

---

## Фаза 2 — Слой данных

### Task 7: FamilyRepository

**Files:**
- Create: `residents/src/Repository/FamilyRepository.php`
- Test: `residents/tests/FamilyRepositoryTest.php`

- [ ] **Step 1: Падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\FamilyRepository;

final class FamilyRepositoryTest extends TestCase
{
    private FamilyRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new FamilyRepository();
    }

    public function test_create_pending_and_find_by_email(): void
    {
        $id = $this->repo->createPending('semya@skaz-kray.ru', 'HASH', 'Поместье Ивановых');
        $f = $this->repo->findByEmail('semya@skaz-kray.ru');
        $this->assertSame($id, (int) $f['id']);
        $this->assertSame('pending', $f['status']);
        $this->assertSame('resident', $f['role']);
    }

    public function test_approve_sets_active(): void
    {
        $id = $this->repo->createPending('a@b.ru', 'H', 'Дом');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $f = $this->repo->findById($id);
        $this->assertSame('active', $f['status']);
        $this->assertSame('2026-08-28 10:00:00', $f['approved_at']);
    }

    public function test_list_pending(): void
    {
        $this->repo->createPending('a@b.ru', 'H', 'Дом А');
        $id = $this->repo->createPending('c@d.ru', 'H', 'Дом B');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $pending = $this->repo->listByStatus('pending');
        $this->assertCount(1, $pending);
        $this->assertSame('Дом А', $pending[0]['name']);
    }

    public function test_update_password(): void
    {
        $id = $this->repo->createPending('a@b.ru', 'OLD', 'Дом');
        $this->repo->updatePassword($id, 'NEW');
        $this->assertSame('NEW', $this->repo->findById($id)['password_hash']);
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter FamilyRepositoryTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class FamilyRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function createPending(string $email, string $passwordHash, string $name): int
    {
        $st = $this->db->prepare(
            'INSERT INTO families (email, password_hash, name, status, role)
             VALUES (?, ?, ?, \'pending\', \'resident\')'
        );
        $st->execute([$email, $passwordHash, $name]);
        return (int) $this->db->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM families WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE families SET status = \'active\', approved_at = ? WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $st = $this->db->prepare('UPDATE families SET status = ? WHERE id = ?');
        $st->execute([$status, $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $st = $this->db->prepare('UPDATE families SET password_hash = ? WHERE id = ?');
        $st->execute([$passwordHash, $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listByStatus(string $status): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM families WHERE status = ? ORDER BY created_at ASC'
        );
        $st->execute([$status]);
        return $st->fetchAll();
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter FamilyRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Repository/FamilyRepository.php residents/tests/FamilyRepositoryTest.php
git commit -m "feat(residents): FamilyRepository"
```

---

### Task 8: DiaryRepository

**Files:**
- Create: `residents/src/Repository/DiaryRepository.php`
- Test: `residents/tests/DiaryRepositoryTest.php`

Правила статусов (ключевое): создание → `pending`; approve → `published` + `published_at`; reject → `rejected` + `reject_reason`; правка своей записи → снова `pending` (сброс `reject_reason`), обновляется `updated_at`.

- [ ] **Step 1: Падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\DiaryRepository;
use SkazResidents\Repository\FamilyRepository;

final class DiaryRepositoryTest extends TestCase
{
    private DiaryRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new DiaryRepository();
    }

    public function test_create_is_pending(): void
    {
        $id = $this->repo->create($this->familyId, 'Весна', 'Посадили сад', '2026-08-28 09:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['published_at']);
    }

    public function test_approve_publishes(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('published', $e['status']);
        $this->assertSame('2026-08-28 10:00:00', $e['published_at']);
    }

    public function test_reject_stores_reason(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $e = $this->repo->findById($id);
        $this->assertSame('rejected', $e['status']);
        $this->assertSame('Нет фото', $e['reject_reason']);
    }

    public function test_edit_returns_to_pending_and_clears_reason(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->reject($id, 'Нет фото');
        $this->repo->update($id, 'T2', 'B2', '2026-08-28 11:00:00');
        $e = $this->repo->findById($id);
        $this->assertSame('pending', $e['status']);
        $this->assertNull($e['reject_reason']);
        $this->assertSame('T2', $e['title']);
    }

    public function test_list_published_only(): void
    {
        $p = $this->repo->create($this->familyId, 'Опубл', 'B', '2026-08-28 09:00:00');
        $this->repo->approve($p, '2026-08-28 10:00:00');
        $this->repo->create($this->familyId, 'Черновик', 'B', '2026-08-28 09:30:00');
        $rows = $this->repo->listPublished(10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Опубл', $rows[0]['title']);
        $this->assertArrayHasKey('family_name', $rows[0]); // джойн имени семьи
    }

    public function test_list_by_family(): void
    {
        $this->repo->create($this->familyId, 'Моя', 'B', '2026-08-28 09:00:00');
        $rows = $this->repo->listByFamily($this->familyId);
        $this->assertCount(1, $rows);
    }

    public function test_delete(): void
    {
        $id = $this->repo->create($this->familyId, 'T', 'B', '2026-08-28 09:00:00');
        $this->repo->delete($id);
        $this->assertNull($this->repo->findById($id));
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter DiaryRepositoryTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class DiaryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $title, string $body, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO diary_entries (family_id, title, body, status, created_at, updated_at)
             VALUES (?, ?, ?, \'pending\', ?, ?)'
        );
        $st->execute([$familyId, $title, $body, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $body, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE diary_entries
             SET title = ?, body = ?, status = \'pending\', reject_reason = NULL,
                 published_at = NULL, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([$title, $body, $now, $id]);
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE diary_entries
             SET status = \'published\', published_at = ?, reject_reason = NULL
             WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function reject(int $id, string $reason): void
    {
        $st = $this->db->prepare(
            'UPDATE diary_entries SET status = \'rejected\', reject_reason = ? WHERE id = ?'
        );
        $st->execute([$reason, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM diary_entries WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPublished(int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.status = \'published\'
             ORDER BY d.published_at DESC
             LIMIT ? OFFSET ?'
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->bindValue(2, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function countPublished(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM diary_entries WHERE status = \'published\''
        )->fetchColumn();
    }

    public function findPublishedById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.id = ? AND d.status = \'published\''
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM diary_entries WHERE family_id = ? ORDER BY updated_at DESC'
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> Все ожидающие модерации, с именем семьи. */
    public function listPending(): array
    {
        return $this->db->query(
            'SELECT d.*, f.name AS family_name
             FROM diary_entries d JOIN families f ON f.id = d.family_id
             WHERE d.status = \'pending\' ORDER BY d.created_at ASC'
        )->fetchAll();
    }

    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM diary_entries WHERE id = ?');
        $st->execute([$id]);
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter DiaryRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Repository/DiaryRepository.php residents/tests/DiaryRepositoryTest.php
git commit -m "feat(residents): DiaryRepository с правилами модерации"
```

---

### Task 9: ProductRepository

**Files:**
- Create: `residents/src/Repository/ProductRepository.php`
- Test: `residents/tests/ProductRepositoryTest.php`

Правила статусов идентичны дневнику; поля другие (`description`, `price` nullable, `contact`).

- [ ] **Step 1: Падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\ProductRepository;
use SkazResidents\Repository\FamilyRepository;

final class ProductRepositoryTest extends TestCase
{
    private ProductRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new ProductRepository();
    }

    public function test_create_is_pending_with_nullable_price(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'Липовый', null, 'тел 8-900', '2026-08-28 09:00:00');
        $p = $this->repo->findById($id);
        $this->assertSame('pending', $p['status']);
        $this->assertNull($p['price']);
        $this->assertSame('тел 8-900', $p['contact']);
    }

    public function test_approve_publishes(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $this->assertSame('published', $this->repo->findById($id)['status']);
    }

    public function test_edit_returns_to_pending(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $this->repo->update($id, 'Мёд 2', 'D2', null, 'C2', '2026-08-28 11:00:00');
        $this->assertSame('pending', $this->repo->findById($id)['status']);
    }

    public function test_list_published_has_family_name(): void
    {
        $id = $this->repo->create($this->familyId, 'Мёд', 'D', '500 ₽', 'C', '2026-08-28 09:00:00');
        $this->repo->approve($id, '2026-08-28 10:00:00');
        $rows = $this->repo->listPublished(10, 0);
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('family_name', $rows[0]);
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter ProductRepositoryTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ProductRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $title, string $description, ?string $price, string $contact, string $now): int
    {
        $st = $this->db->prepare(
            'INSERT INTO products (family_id, title, description, price, contact, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?)'
        );
        $st->execute([$familyId, $title, $description, $price, $contact, $now, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, string $description, ?string $price, string $contact, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE products
             SET title = ?, description = ?, price = ?, contact = ?,
                 status = \'pending\', reject_reason = NULL, published_at = NULL, updated_at = ?
             WHERE id = ?'
        );
        $st->execute([$title, $description, $price, $contact, $now, $id]);
    }

    public function approve(int $id, string $now): void
    {
        $st = $this->db->prepare(
            'UPDATE products SET status = \'published\', published_at = ?, reject_reason = NULL WHERE id = ?'
        );
        $st->execute([$now, $id]);
    }

    public function reject(int $id, string $reason): void
    {
        $st = $this->db->prepare(
            'UPDATE products SET status = \'rejected\', reject_reason = ? WHERE id = ?'
        );
        $st->execute([$reason, $id]);
    }

    public function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPublished(int $limit, int $offset): array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.status = \'published\'
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?'
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->bindValue(2, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function countPublished(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM products WHERE status = \'published\''
        )->fetchColumn();
    }

    public function findPublishedById(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.id = ? AND p.status = \'published\''
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listByFamily(int $familyId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM products WHERE family_id = ? ORDER BY updated_at DESC'
        );
        $st->execute([$familyId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listPending(): array
    {
        return $this->db->query(
            'SELECT p.*, f.name AS family_name
             FROM products p JOIN families f ON f.id = p.family_id
             WHERE p.status = \'pending\' ORDER BY p.created_at ASC'
        )->fetchAll();
    }

    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $st->execute([$id]);
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter ProductRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Repository/ProductRepository.php residents/tests/ProductRepositoryTest.php
git commit -m "feat(residents): ProductRepository"
```

---

### Task 10: ImageRepository + Upload

**Files:**
- Create: `residents/src/Repository/ImageRepository.php`
- Create: `residents/src/Upload.php`
- Test: `residents/tests/ImageRepositoryTest.php`

- [ ] **Step 1: Падающий тест (репозиторий)**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\ImageRepository;

final class ImageRepositoryTest extends TestCase
{
    private ImageRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new ImageRepository();
    }

    public function test_add_and_list_for_owner(): void
    {
        $this->repo->add('entry', 5, 'a.jpg', 0);
        $this->repo->add('entry', 5, 'b.jpg', 1);
        $this->repo->add('product', 5, 'c.jpg', 0); // другой owner_type
        $imgs = $this->repo->listFor('entry', 5);
        $this->assertCount(2, $imgs);
        $this->assertSame('a.jpg', $imgs[0]['path']);
    }

    public function test_delete_for_owner(): void
    {
        $this->repo->add('entry', 5, 'a.jpg', 0);
        $this->repo->deleteFor('entry', 5);
        $this->assertCount(0, $this->repo->listFor('entry', 5));
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter ImageRepositoryTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация ImageRepository**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ImageRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function add(string $ownerType, int $ownerId, string $path, int $sort): int
    {
        $st = $this->db->prepare(
            'INSERT INTO images (owner_type, owner_id, path, sort) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$ownerType, $ownerId, $path, $sort]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function listFor(string $ownerType, int $ownerId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM images WHERE owner_type = ? AND owner_id = ? ORDER BY sort ASC, id ASC'
        );
        $st->execute([$ownerType, $ownerId]);
        return $st->fetchAll();
    }

    public function deleteFor(string $ownerType, int $ownerId): void
    {
        $st = $this->db->prepare('DELETE FROM images WHERE owner_type = ? AND owner_id = ?');
        $st->execute([$ownerType, $ownerId]);
    }

    public function deleteById(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM images WHERE id = ?');
        $st->execute([$id]);
    }
}
```

- [ ] **Step 4: Реализация Upload** (пересохранение через GD со снятием EXIF; проверяется вручную при загрузке в браузере — GD-обработка бинарников не покрывается unit-тестом)

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Upload
{
    private const MAX_BYTES = 5_242_880; // 5 МБ
    private const MAX_DIM   = 1600;      // px по большей стороне

    /**
     * Валидирует и пересохраняет одно изображение из $_FILES-записи.
     * Возвращает имя файла (относительно uploads_dir) либо null с текстом ошибки.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{0:?string,1:?string} [filename, error]
     */
    public static function saveImage(array $file, string $uploadsDir): array
    {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return [null, null]; // файл не приложен — не ошибка
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'Ошибка загрузки файла.'];
        }
        if ($file['size'] > self::MAX_BYTES) {
            return [null, 'Файл больше 5 МБ.'];
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return [null, 'Файл не является изображением.'];
        }
        $mime = $info['mime'];
        if (!Validator::imageMime($mime)) {
            return [null, 'Допустимы только JPEG, PNG и WebP.'];
        }

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
        };
        if ($src === false) {
            return [null, 'Не удалось прочитать изображение.'];
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // bin2hex(random_bytes) — уникальное имя; сохраняем в JPEG (EXIF снимается пересохранением)
        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        imagejpeg($dst, $uploadsDir . '/' . $name, 85);
        imagedestroy($src);
        imagedestroy($dst);

        return [$name, null];
    }
}
```

- [ ] **Step 5: Зелёный (репозиторий)**

Run: `php vendor/bin/phpunit --filter ImageRepositoryTest`
Expected: PASS. `php -l src/Upload.php` → нет ошибок синтаксиса.

- [ ] **Step 6: Commit**

```bash
git add residents/src/Repository/ImageRepository.php residents/src/Upload.php residents/tests/ImageRepositoryTest.php
git commit -m "feat(residents): изображения — репозиторий и обработка загрузки"
```

---

## Фаза 3 — Почта

### Task 11: Mailer (SMTP)

**Files:**
- Create: `residents/src/Mailer.php`
- Test: `residents/tests/MailerTest.php`

Отправка — через простой SMTP-клиент на сокетах (без сторонних зависимостей). Юнит-тестом покрываем сборку письма (заголовки/тело/кодировка), не сетевую отправку.

- [ ] **Step 1: Падающий тест**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Mailer;

final class MailerTest extends TestCase
{
    public function test_build_message_has_utf8_subject_and_body(): void
    {
        $msg = Mailer::buildMessage(
            'noreply@skaz-kray.ru', 'Сказочный Край',
            'semya@skaz-kray.ru', 'Заявка одобрена', "Здравствуйте!\nВаш аккаунт активен."
        );
        $this->assertStringContainsString('To: semya@skaz-kray.ru', $msg);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $msg);
        $this->assertStringContainsString('=?UTF-8?B?', $msg); // MIME-кодированная тема
        $this->assertStringContainsString('Ваш аккаунт активен', $msg);
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter MailerTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализация**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Mailer
{
    /** Собирает RFC-822 сообщение (заголовки + тело). Отдельно для тестируемости. */
    public static function buildMessage(
        string $from, string $fromName, string $to, string $subject, string $body
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'From: ' . $encodedName . ' <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** Отправка через SMTP. Бросает RuntimeException при сбое; вызывающий ловит (fail-open). */
    public static function send(string $to, string $subject, string $body): void
    {
        $cfg = Config::get('smtp');
        $message = self::buildMessage($cfg['from'], $cfg['from_name'], $to, $subject, $body);

        $transport = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'];
        $fp = @stream_socket_client(
            $transport . ':' . $cfg['port'], $errno, $errstr, 15
        );
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        }

        $expect = function (string $code) use ($fp) {
            $line = '';
            while (($l = fgets($fp, 515)) !== false) {
                $line = $l;
                if (isset($l[3]) && $l[3] === ' ') break;
            }
            if (strncmp($line, $code, 3) !== 0) {
                throw new \RuntimeException("SMTP unexpected: $line");
            }
        };
        $cmd = function (string $c) use ($fp) { fwrite($fp, $c . "\r\n"); };

        $expect('220');
        $cmd('EHLO skaz-kray.ru'); $expect('250');
        if ($cfg['secure'] === 'tls') {
            $cmd('STARTTLS'); $expect('220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO skaz-kray.ru'); $expect('250');
        }
        $cmd('AUTH LOGIN'); $expect('334');
        $cmd(base64_encode($cfg['user'])); $expect('334');
        $cmd(base64_encode($cfg['pass'])); $expect('235');
        $cmd('MAIL FROM:<' . $cfg['from'] . '>'); $expect('250');
        $cmd('RCPT TO:<' . $to . '>'); $expect('250');
        $cmd('DATA'); $expect('354');
        fwrite($fp, $message . "\r\n.\r\n"); $expect('250');
        $cmd('QUIT');
        fclose($fp);
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter MailerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Mailer.php residents/tests/MailerTest.php
git commit -m "feat(residents): SMTP-mailer"
```

---

## Фаза 4 — Веб-каркас: роутер, шаблоны, стили

### Task 12: Router + View + Flash + фронт-контроллер + layout

**Files:**
- Create: `residents/src/Router.php`
- Create: `residents/src/View.php`
- Create: `residents/src/Flash.php`
- Create: `residents/public/index.php`
- Create: `residents/src/templates/layout.php`
- Create: `residents/src/templates/partials/flash.php`
- Create: `residents/public/assets/residents.css`
- Test: `residents/tests/RouterTest.php`

- [ ] **Step 1: Падающий тест роутера**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Router;

final class RouterTest extends TestCase
{
    public function test_matches_static_route(): void
    {
        $r = new Router();
        $hit = false;
        $r->get('/poselenie/vhod', function () use (&$hit) { $hit = true; });
        $r->dispatch('GET', '/poselenie/vhod');
        $this->assertTrue($hit);
    }

    public function test_matches_param_route(): void
    {
        $r = new Router();
        $captured = null;
        $r->get('/yarmarka/{id}', function ($params) use (&$captured) { $captured = $params['id']; });
        $r->dispatch('GET', '/yarmarka/42');
        $this->assertSame('42', $captured);
    }

    public function test_returns_false_on_no_match(): void
    {
        $r = new Router();
        $r->get('/a', fn() => null);
        $this->assertFalse($r->dispatch('GET', '/nope'));
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter RouterTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Router**

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Router
{
    /** @var array<int,array{method:string,regex:string,keys:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '([^/]+)';
        }, rtrim($path, '/'));
        $this->routes[] = [
            'method' => $method,
            'regex'  => '#^' . $regex . '$#',
            'keys'   => $keys,
            'handler'=> $handler,
        ];
    }

    /** @return bool true если маршрут найден и вызван. */
    public function dispatch(string $method, string $uri): bool
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        if ($path === '') { $path = '/'; }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) { continue; }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['keys'], $matches) ?: [];
                ($route['handler'])($params);
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 4: Зелёный (роутер)**

Run: `php vendor/bin/phpunit --filter RouterTest`
Expected: PASS.

- [ ] **Step 5: View** (`src/View.php`)

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class View
{
    /** Рендер шаблона внутри layout. $data извлекается в область видимости шаблона. */
    public static function render(string $template, array $data = [], string $title = ''): void
    {
        extract($data, EXTR_SKIP);
        $templateFile = __DIR__ . '/templates/' . $template . '.php';
        ob_start();
        require $templateFile;
        $content = ob_get_clean();
        require __DIR__ . '/templates/layout.php';
    }

    /** Экранирование для вывода в HTML. */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 6: Flash** (`src/Flash.php`)

```php
<?php
declare(strict_types=1);
namespace SkazResidents;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function take(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
```

- [ ] **Step 7: layout.php** (`src/templates/layout.php`) — каркас в токенах сайта

```php
<?php use SkazResidents\View; use SkazResidents\Auth; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title) ?> — Сказочный Край</title>
    <link rel="stylesheet" href="/poselenie/assets/residents.css">
</head>
<body>
<header class="res-header">
    <a class="res-logo" href="/">Сказочный Край</a>
    <nav class="res-nav">
        <a href="/dnevniki-pomestiy/">Дневники поместий</a>
        <a href="/yarmarka/">Ярмарка</a>
        <?php if (Auth::id() !== null): ?>
            <a href="/poselenie/">Кабинет</a>
            <?php if (Auth::isEditor()): ?><a href="/poselenie/moderation">Модерация</a><?php endif; ?>
            <a href="/poselenie/vyhod">Выход</a>
        <?php else: ?>
            <a href="/poselenie/vhod">Вход для жителей</a>
        <?php endif; ?>
    </nav>
</header>
<main class="res-main">
    <?php require __DIR__ . '/partials/flash.php'; ?>
    <?= $content ?>
</main>
<footer class="res-footer">
    <p>Поселение родовых поместий «Сказочный Край»</p>
</footer>
</body>
</html>
```

- [ ] **Step 8: partials/flash.php**

```php
<?php use SkazResidents\Flash; use SkazResidents\View; ?>
<?php foreach (Flash::take() as $f): ?>
    <div class="res-flash res-flash--<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
<?php endforeach; ?>
```

- [ ] **Step 9: assets/residents.css** — токены сайта

```css
:root {
    --green: #008757;
    --ochre: #c98a3a;
    --ink: #2b2b2b;
    --paper: #fbfaf6;
    --muted: #6b6b6b;
}
* { box-sizing: border-box; }
body {
    margin: 0; background: var(--paper); color: #2b2b2b;
    font-family: "PT Sans", system-ui, sans-serif; font-size: 18px; line-height: 1.6;
}
h1, h2, h3 { font-family: "PT Serif", Georgia, serif; color: var(--green); }
a { color: var(--green); }
.res-header {
    display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between;
    padding: 1rem 1.5rem; border-bottom: 2px solid var(--green); background: #fff;
}
.res-logo { font-family: "PT Serif", Georgia, serif; font-size: 1.4rem; font-weight: 700; text-decoration: none; }
.res-nav { display: flex; flex-wrap: wrap; gap: 1rem; }
.res-nav a { text-decoration: none; }
.res-main { max-width: 820px; margin: 0 auto; padding: 2rem 1.5rem; }
.res-footer { text-align: center; color: var(--muted); padding: 2rem 1rem; }
.res-flash { padding: .8rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
.res-flash--success { background: #e5f4ec; color: #0a5; }
.res-flash--error { background: #fbe9e7; color: #c0392b; }
.res-flash--info { background: #fff6e5; color: var(--ochre); }
.res-form label { display: block; margin: 1rem 0 .3rem; font-weight: 700; }
.res-form input, .res-form textarea {
    width: 100%; padding: .6rem; font: inherit; border: 1px solid #ccc; border-radius: 6px; background: #fff;
}
.res-form textarea { min-height: 8rem; }
.res-btn {
    display: inline-block; margin-top: 1.2rem; padding: .6rem 1.4rem; border: 0; border-radius: 6px;
    background: var(--green); color: #fff; font: inherit; cursor: pointer; text-decoration: none;
}
.res-btn--ghost { background: transparent; color: var(--green); border: 1px solid var(--green); }
.res-card { background: #fff; border: 1px solid #eee; border-radius: 10px; padding: 1.2rem; margin-bottom: 1.2rem; }
.res-card img { max-width: 100%; height: auto; border-radius: 8px; }
.res-meta { color: var(--muted); font-size: .9rem; }
.res-status { font-size: .85rem; padding: .15rem .6rem; border-radius: 999px; }
.res-status--pending { background: #fff6e5; color: var(--ochre); }
.res-status--published { background: #e5f4ec; color: #0a5; }
.res-status--rejected { background: #fbe9e7; color: #c0392b; }
```

Примечание: в `--ink` выше опечатка кириллицей — заменить строку на `--ink: #2b2b2b;` при вводе (значение всё равно не используется, но держим файл чистым).

- [ ] **Step 10: public/index.php** — фронт-контроллер (маршруты дополняются в следующих задачах)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use SkazResidents\Router;
use SkazResidents\View;

$router = new Router();

// Проверочный маршрут (удалить после Task 13)
$router->get('/poselenie/ping', function () {
    View::render('auth/ping', [], 'ping');
});

$found = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
if (!$found) {
    http_response_code(404);
    View::render('public/notfound', [], 'Страница не найдена');
}
```

- [ ] **Step 11: Заглушки шаблонов для проверки**

Create `residents/src/templates/auth/ping.php`:

```php
<h1>Раздел жителей работает</h1>
<p>PHP-приложение отвечает.</p>
```

Create `residents/src/templates/public/notfound.php`:

```php
<h1>Страница не найдена</h1>
<p><a href="/">На главную</a></p>
```

- [ ] **Step 12: Проверка синтаксиса + smoke через встроенный сервер (опционально, если есть локальная БД)**

Run: `php -l public/index.php && php -l src/Router.php && php -l src/View.php`
Expected: `No syntax errors detected`.
Полный smoke (`php -S 127.0.0.1:8080 -t public` + открыть `/poselenie/ping`) требует `config.php` и БД — делается на этапе развёртывания (Task 20).

- [ ] **Step 13: Commit**

```bash
git add residents/src/Router.php residents/src/View.php residents/src/Flash.php residents/public/index.php residents/src/templates residents/public/assets residents/tests/RouterTest.php
git commit -m "feat(residents): роутер, рендер шаблонов, layout и стили"
```

---

## Фаза 5 — Потоки входа

### Task 13: Регистрация, вход, выход

**Files:**
- Create: `residents/src/Controller/AuthController.php`
- Create: `residents/src/templates/auth/register.php`
- Create: `residents/src/templates/auth/login.php`
- Modify: `residents/public/index.php` (маршруты)

Правила: регистрация создаёт семью со статусом `pending` (войти нельзя до одобрения). Вход разрешён только при `status = active`. При `pending`/`blocked` — понятное сообщение. Троттлинг: не более `login_throttle.max` неудач за окно по email.

- [ ] **Step 1: AuthController** (`src/Controller/AuthController.php`)

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Database};
use SkazResidents\Repository\FamilyRepository;

final class AuthController
{
    public function __construct(private FamilyRepository $families = new FamilyRepository()) {}

    public function showRegister(): void
    {
        View::render('auth/register', ['old' => [], 'errors' => []], 'Регистрация семьи');
    }

    public function register(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $errors = [];

        if (!Validator::email($email)) { $errors['email'] = 'Укажите корректный email.'; }
        if (!Validator::length($name, 2, 160)) { $errors['name'] = 'Название семьи/поместья: 2–160 символов.'; }
        if (!Validator::password($pass)) { $errors['password'] = 'Пароль не короче 8 символов.'; }
        if (!$errors && $this->families->findByEmail($email)) {
            $errors['email'] = 'Такой email уже зарегистрирован.';
        }

        if ($errors) {
            View::render('auth/register', ['old' => compact('email', 'name'), 'errors' => $errors], 'Регистрация семьи');
            return;
        }

        $this->families->createPending($email, Auth::hash($pass), $name);
        Flash::set('success', 'Заявка отправлена. После одобрения редактором вы сможете войти.');
        header('Location: /poselenie/vhod');
    }

    public function showLogin(): void
    {
        View::render('auth/login', ['old' => [], 'error' => null], 'Вход для жителей');
    }

    public function login(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $email = trim($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($this->isThrottled($email)) {
            View::render('auth/login', ['old' => compact('email'), 'error' => 'Слишком много попыток. Попробуйте позже.'], 'Вход для жителей');
            return;
        }

        $family = $this->families->findByEmail($email);
        $ok = $family && Auth::verify($pass, $family['password_hash']);

        if (!$ok) {
            $this->recordFailure($email, $ip);
            View::render('auth/login', ['old' => compact('email'), 'error' => 'Неверный email или пароль.'], 'Вход для жителей');
            return;
        }
        if ($family['status'] !== 'active') {
            $msg = $family['status'] === 'pending'
                ? 'Заявка ещё не одобрена редактором.'
                : 'Доступ заблокирован. Обратитесь к редактору поселения.';
            View::render('auth/login', ['old' => compact('email'), 'error' => $msg], 'Вход для жителей');
            return;
        }

        Auth::login($family);
        header('Location: /poselenie/');
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /poselenie/vhod');
    }

    // Переносимо между MariaDB и SQLite: сравниваем время попыток в PHP,
    // а не в SQL-выражении (у СУБД разный синтаксис работы с датами).
    private function isThrottled(string $email): bool
    {
        $cfg = Config::get('login_throttle');
        $st = Database::pdo()->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE email = ? ORDER BY attempted_at DESC LIMIT 50'
        );
        $st->execute([$email]);
        $cutoff = time() - (int) $cfg['window'];
        $recent = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $ts) {
            if (strtotime((string) $ts) >= $cutoff) { $recent++; }
        }
        return $recent >= (int) $cfg['max'];
    }

    private function recordFailure(string $email, string $ip): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)'
        );
        $st->execute([$email, $ip, date('Y-m-d H:i:s')]);
    }
}
```

Примечание: `attempted_at` пишем явно из PHP (`date('Y-m-d H:i:s')`), чтобы `strtotime` в `isThrottled` разбирал одинаковый формат на обеих СУБД, не полагаясь на DEFAULT CURRENT_TIMESTAMP.

- [ ] **Step 2: templates/auth/register.php**

```php
<?php use SkazResidents\{Csrf, View}; ?>
<h1>Регистрация семьи</h1>
<p>Заполните заявку. После одобрения редактором вы сможете войти в кабинет.</p>
<form class="res-form" method="post" action="/poselenie/register">
    <?= Csrf::field() ?>
    <label>Название семьи / поместья
        <input type="text" name="name" value="<?= View::e($old['name'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['name'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['name']) ?></div><?php endif; ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['email'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['email']) ?></div><?php endif; ?>
    <label>Пароль (не короче 8 символов)
        <input type="password" name="password" required>
    </label>
    <?php if (isset($errors['password'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['password']) ?></div><?php endif; ?>
    <button class="res-btn" type="submit">Отправить заявку</button>
</form>
<p class="res-meta">Уже есть аккаунт? <a href="/poselenie/vhod">Войти</a>.</p>
```

- [ ] **Step 3: templates/auth/login.php**

```php
<?php use SkazResidents\{Csrf, View}; ?>
<h1>Вход для жителей</h1>
<?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
<form class="res-form" method="post" action="/poselenie/login">
    <?= Csrf::field() ?>
    <label>Email
        <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required>
    </label>
    <label>Пароль
        <input type="password" name="password" required>
    </label>
    <button class="res-btn" type="submit">Войти</button>
</form>
<p class="res-meta">
    Нет аккаунта? <a href="/poselenie/register">Подать заявку</a>.<br>
    <a href="/poselenie/vosstanovit">Забыли пароль?</a>
</p>
```

- [ ] **Step 4: Подключить маршруты в public/index.php**

Заменить блок с `ping`-маршрутом на:

```php
use SkazResidents\Controller\AuthController;

$auth = new AuthController();
$router->get('/poselenie/register', [$auth, 'showRegister']);
$router->post('/poselenie/register', [$auth, 'register']);
$router->get('/poselenie/vhod', [$auth, 'showLogin']);
$router->post('/poselenie/login', [$auth, 'login']);
$router->get('/poselenie/vyhod', [$auth, 'logout']);
```

- [ ] **Step 5: Проверка синтаксиса**

Run: `php -l src/Controller/AuthController.php && php -l public/index.php`
Expected: нет ошибок. Полные тесты: `php vendor/bin/phpunit` → всё зелёное (существующие тесты не сломаны).

- [ ] **Step 6: Commit**

```bash
git add residents/src/Controller/AuthController.php residents/src/templates/auth residents/public/index.php
git commit -m "feat(residents): регистрация, вход и выход"
```

---

### Task 14: Восстановление пароля по email

**Files:**
- Create: `residents/src/Repository/ResetRepository.php`
- Create: `residents/src/templates/auth/forgot.php`
- Create: `residents/src/templates/auth/reset.php`
- Modify: `residents/src/Controller/AuthController.php`
- Modify: `residents/public/index.php`
- Test: `residents/tests/ResetRepositoryTest.php`

- [ ] **Step 1: Падающий тест репозитория**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{ResetRepository, FamilyRepository};

final class ResetRepositoryTest extends TestCase
{
    private ResetRepository $repo;
    private int $familyId;

    protected function setUp(): void
    {
        make_test_db();
        $this->familyId = (new FamilyRepository())->createPending('a@b.ru', 'H', 'Дом');
        $this->repo = new ResetRepository();
    }

    public function test_create_and_consume_valid_token(): void
    {
        $token = $this->repo->create($this->familyId, '2999-01-01 00:00:00');
        $this->assertSame(64, strlen($token));
        $row = $this->repo->findValid($token, '2026-08-28 12:00:00');
        $this->assertSame($this->familyId, (int) $row['family_id']);
    }

    public function test_expired_token_is_invalid(): void
    {
        $token = $this->repo->create($this->familyId, '2026-08-28 10:00:00');
        $this->assertNull($this->repo->findValid($token, '2026-08-28 12:00:00'));
    }

    public function test_delete_token(): void
    {
        $token = $this->repo->create($this->familyId, '2999-01-01 00:00:00');
        $this->repo->delete($token);
        $this->assertNull($this->repo->findValid($token, '2026-08-28 12:00:00'));
    }
}
```

- [ ] **Step 2: Падает**

Run: `php vendor/bin/phpunit --filter ResetRepositoryTest`
Expected: FAIL — класс не найден.

- [ ] **Step 3: ResetRepository**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\Database;
use PDO;

final class ResetRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function create(int $familyId, string $expiresAt): string
    {
        $token = bin2hex(random_bytes(32));
        $st = $this->db->prepare(
            'INSERT INTO password_resets (token, family_id, expires_at) VALUES (?, ?, ?)'
        );
        $st->execute([$token, $familyId, $expiresAt]);
        return $token;
    }

    /** Возвращает строку, если токен существует и ещё не истёк по переданному "сейчас". */
    public function findValid(string $token, string $now): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM password_resets WHERE token = ? AND expires_at > ?'
        );
        $st->execute([$token, $now]);
        return $st->fetch() ?: null;
    }

    public function delete(string $token): void
    {
        $st = $this->db->prepare('DELETE FROM password_resets WHERE token = ?');
        $st->execute([$token]);
    }
}
```

- [ ] **Step 4: Зелёный**

Run: `php vendor/bin/phpunit --filter ResetRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Дописать методы в AuthController**

Добавить в `AuthController` (конструктор дополнить репозиторием сброса):

```php
    public function __construct(
        private FamilyRepository $families = new FamilyRepository(),
        private \SkazResidents\Repository\ResetRepository $resets = new \SkazResidents\Repository\ResetRepository()
    ) {}

    public function showForgot(): void
    {
        View::render('auth/forgot', ['sent' => false], 'Восстановление пароля');
    }

    public function forgot(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $email = trim($_POST['email'] ?? '');
        $family = Validator::email($email) ? $this->families->findByEmail($email) : null;

        // Всегда показываем "письмо отправлено" — не раскрываем, есть ли такой email.
        if ($family && $family['status'] === 'active') {
            $ttl = (int) Config::get('reset_ttl', 3600);
            $expires = date('Y-m-d H:i:s', time() + $ttl);
            $token = $this->resets->create((int) $family['id'], $expires);
            $link = Config::get('base_url') . '/poselenie/sbros?token=' . $token;
            try {
                \SkazResidents\Mailer::send(
                    $email, 'Восстановление пароля — Сказочный Край',
                    "Здравствуйте!\n\nЧтобы задать новый пароль, перейдите по ссылке (действует час):\n$link\n\nЕсли вы не запрашивали сброс — просто игнорируйте письмо."
                );
            } catch (\Throwable $e) {
                error_log('reset mail failed: ' . $e->getMessage());
            }
        }
        View::render('auth/forgot', ['sent' => true], 'Восстановление пароля');
    }

    public function showReset(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль'); return; }
        View::render('auth/reset', ['valid' => true, 'token' => $token, 'error' => null], 'Новый пароль');
    }

    public function reset(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $token = (string) ($_POST['token'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $row = $this->resets->findValid($token, date('Y-m-d H:i:s'));
        if (!$row) { View::render('auth/reset', ['valid' => false, 'token' => ''], 'Новый пароль'); return; }
        if (!Validator::password($pass)) {
            View::render('auth/reset', ['valid' => true, 'token' => $token, 'error' => 'Пароль не короче 8 символов.'], 'Новый пароль');
            return;
        }
        $this->families->updatePassword((int) $row['family_id'], Auth::hash($pass));
        $this->resets->delete($token);
        Flash::set('success', 'Пароль обновлён. Теперь войдите с новым паролем.');
        header('Location: /poselenie/vhod');
    }
```

- [ ] **Step 6: templates/auth/forgot.php**

```php
<?php use SkazResidents\Csrf; ?>
<h1>Восстановление пароля</h1>
<?php if (!empty($sent)): ?>
    <div class="res-flash res-flash--success">Если такой email зарегистрирован, мы отправили на него ссылку для сброса пароля. Если письмо не пришло — обратитесь к редактору поселения, он сбросит пароль вручную.</div>
<?php else: ?>
    <form class="res-form" method="post" action="/poselenie/vosstanovit">
        <?= Csrf::field() ?>
        <label>Email
            <input type="email" name="email" required>
        </label>
        <button class="res-btn" type="submit">Прислать ссылку</button>
    </form>
<?php endif; ?>
```

- [ ] **Step 7: templates/auth/reset.php**

```php
<?php use SkazResidents\{Csrf, View}; ?>
<h1>Новый пароль</h1>
<?php if (empty($valid)): ?>
    <div class="res-flash res-flash--error">Ссылка недействительна или устарела. <a href="/poselenie/vosstanovit">Запросить заново</a>.</div>
<?php else: ?>
    <?php if (!empty($error)): ?><div class="res-flash res-flash--error"><?= View::e($error) ?></div><?php endif; ?>
    <form class="res-form" method="post" action="/poselenie/sbros">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= View::e($token) ?>">
        <label>Новый пароль (не короче 8 символов)
            <input type="password" name="password" required>
        </label>
        <button class="res-btn" type="submit">Сохранить</button>
    </form>
<?php endif; ?>
```

- [ ] **Step 8: Маршруты в public/index.php**

```php
$router->get('/poselenie/vosstanovit', [$auth, 'showForgot']);
$router->post('/poselenie/vosstanovit', [$auth, 'forgot']);
$router->get('/poselenie/sbros', [$auth, 'showReset']);
$router->post('/poselenie/sbros', [$auth, 'reset']);
```

- [ ] **Step 9: Проверка**

Run: `php vendor/bin/phpunit && php -l src/Controller/AuthController.php`
Expected: все тесты зелёные, нет ошибок синтаксиса.

- [ ] **Step 10: Commit**

```bash
git add residents/src/Repository/ResetRepository.php residents/src/Controller/AuthController.php residents/src/templates/auth residents/public/index.php residents/tests/ResetRepositoryTest.php
git commit -m "feat(residents): восстановление пароля по email"
```

---

## Фаза 6 — Кабинет и дневник

### Task 15: Кабинет (обзор своих записей и товаров)

**Files:**
- Create: `residents/src/Controller/CabinetController.php`
- Create: `residents/src/templates/cabinet/index.php`
- Modify: `residents/public/index.php`

- [ ] **Step 1: CabinetController**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Repository\{DiaryRepository, ProductRepository};

final class CabinetController
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $familyId = Auth::id();
        View::render('cabinet/index', [
            'entries'  => $this->diary->listByFamily($familyId),
            'products' => $this->products->listByFamily($familyId),
        ], 'Личный кабинет');
    }
}
```

- [ ] **Step 2: templates/cabinet/index.php**

```php
<?php use SkazResidents\View; ?>
<h1>Личный кабинет</h1>

<section>
    <h2>Дневник поместья</h2>
    <p><a class="res-btn" href="/poselenie/dnevnik/novaya">Новая запись</a></p>
    <?php if (!$entries): ?><p class="res-meta">Записей пока нет.</p><?php endif; ?>
    <?php foreach ($entries as $e): ?>
        <div class="res-card">
            <strong><?= View::e($e['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($e['status']) ?>"><?= View::e(status_label($e['status'])) ?></span>
            <?php if ($e['status'] === 'rejected' && $e['reject_reason']): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($e['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta">
                <a href="/poselenie/dnevnik/<?= (int) $e['id'] ?>/redaktirovat">Редактировать</a> ·
                <a href="/poselenie/dnevnik/<?= (int) $e['id'] ?>/udalit" onclick="return confirm('Удалить запись?')">Удалить</a>
            </p>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Мои товары и услуги</h2>
    <p><a class="res-btn" href="/poselenie/yarmarka/novyy">Добавить товар/услугу</a></p>
    <?php if (!$products): ?><p class="res-meta">Пока ничего не добавлено.</p><?php endif; ?>
    <?php foreach ($products as $p): ?>
        <div class="res-card">
            <strong><?= View::e($p['title']) ?></strong>
            <span class="res-status res-status--<?= View::e($p['status']) ?>"><?= View::e(status_label($p['status'])) ?></span>
            <?php if ($p['status'] === 'rejected' && $p['reject_reason']): ?>
                <div class="res-flash res-flash--error">Причина: <?= View::e($p['reject_reason']) ?></div>
            <?php endif; ?>
            <p class="res-meta">
                <a href="/poselenie/yarmarka/<?= (int) $p['id'] ?>/redaktirovat">Редактировать</a> ·
                <a href="/poselenie/yarmarka/<?= (int) $p['id'] ?>/udalit" onclick="return confirm('Удалить?')">Удалить</a>
            </p>
        </div>
    <?php endforeach; ?>
</section>
```

- [ ] **Step 3: Хелпер статусов** — добавить в `src/bootstrap.php` перед `Database::connect(...)`:

```php
function status_label(string $status): string
{
    return match ($status) {
        'pending'   => 'на проверке',
        'published' => 'опубликовано',
        'rejected'  => 'отклонено',
        default     => $status,
    };
}
```

- [ ] **Step 4: Маршрут в public/index.php**

```php
use SkazResidents\Controller\CabinetController;
$cabinet = new CabinetController();
$router->get('/poselenie', [$cabinet, 'index']);
$router->get('/poselenie/', [$cabinet, 'index']);
```

- [ ] **Step 5: Проверка**

Run: `php -l src/Controller/CabinetController.php && php vendor/bin/phpunit`
Expected: нет ошибок, тесты зелёные.

- [ ] **Step 6: Commit**

```bash
git add residents/src/Controller/CabinetController.php residents/src/templates/cabinet residents/src/bootstrap.php residents/public/index.php
git commit -m "feat(residents): личный кабинет — обзор записей и товаров"
```

---

### Task 16: CRUD дневника (создание, редактирование, удаление, загрузка фото)

**Files:**
- Create: `residents/src/Controller/DiaryController.php`
- Create: `residents/src/templates/diary/form.php`
- Modify: `residents/public/index.php`

Логика: создание/правка проходят валидацию, пишут через `DiaryRepository`, загружают фото через `Upload`+`ImageRepository`. Владение проверяется (нельзя править чужую запись). Правка возвращает запись на модерацию (репозиторий уже это делает).

- [ ] **Step 1: DiaryController**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{DiaryRepository, ImageRepository};

final class DiaryController
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('diary/form', ['entry' => null, 'images' => [], 'errors' => []], 'Новая запись');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('diary/form', ['entry' => $data, 'images' => [], 'errors' => $errors], 'Новая запись');
            return;
        }
        $now = date('Y-m-d H:i:s');
        $id = $this->diary->create(Auth::id(), $data['title'], $data['body'], $now);
        $this->handleUploads('entry', $id);
        Flash::set('success', 'Запись отправлена на проверку редактору.');
        header('Location: /poselenie/');
    }

    public function showEdit(array $params): void
    {
        Auth::requireLogin();
        $entry = $this->ownedOr404((int) $params['id']);
        View::render('diary/form', [
            'entry' => $entry,
            'images' => $this->images->listFor('entry', (int) $entry['id']),
            'errors' => [],
        ], 'Редактирование записи');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $entry = $this->ownedOr404((int) $params['id']);

        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $entry['id'];
            View::render('diary/form', ['entry' => $data, 'images' => $this->images->listFor('entry', (int) $entry['id']), 'errors' => $errors], 'Редактирование записи');
            return;
        }
        $this->diary->update((int) $entry['id'], $data['title'], $data['body'], date('Y-m-d H:i:s'));
        $this->handleUploads('entry', (int) $entry['id']);
        Flash::set('success', 'Изменения отправлены на повторную проверку.');
        header('Location: /poselenie/');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $entry = $this->ownedOr404((int) $params['id']);
        $this->images->deleteFor('entry', (int) $entry['id']);
        $this->diary->delete((int) $entry['id']);
        Flash::set('success', 'Запись удалена.');
        header('Location: /poselenie/');
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Заголовок: 2–200 символов.'; }
        if (!Validator::required($body)) { $errors['body'] = 'Текст записи не может быть пустым.'; }
        return [['title' => $title, 'body' => $body], $errors];
    }

    private function handleUploads(string $ownerType, int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $files = $this->normalizeFiles($_FILES['photos']);
        $sort = count($this->images->listFor($ownerType, $ownerId));
        foreach ($files as $file) {
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) {
                $this->images->add($ownerType, $ownerId, $name, $sort++);
            } elseif ($err !== null) {
                Flash::set('error', $err);
            }
        }
    }

    /** Приводит массив $_FILES[multiple] к списку одиночных записей. */
    private function normalizeFiles(array $f): array
    {
        if (!is_array($f['name'])) { return [$f]; }
        $out = [];
        foreach ($f['name'] as $i => $_) {
            $out[] = [
                'name' => $f['name'][$i], 'type' => $f['type'][$i],
                'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ];
        }
        return $out;
    }

    private function ownedOr404(int $id): array
    {
        $entry = $this->diary->findById($id);
        if (!$entry || (int) $entry['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Запись не найдена');
            exit;
        }
        return $entry;
    }
}
```

- [ ] **Step 2: templates/diary/form.php**

```php
<?php use SkazResidents\{Csrf, View};
$isEdit = !empty($entry['id']);
$action = $isEdit ? '/poselenie/dnevnik/' . (int) $entry['id'] . '/redaktirovat' : '/poselenie/dnevnik/novaya';
?>
<h1><?= $isEdit ? 'Редактирование записи' : 'Новая запись дневника' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Заголовок
        <input type="text" name="title" value="<?= View::e($entry['title'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Текст записи
        <textarea name="body" required><?= View::e($entry['body'] ?? '') ?></textarea>
    </label>
    <?php if (isset($errors['body'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['body']) ?></div><?php endif; ?>
    <label>Фотографии (JPEG/PNG/WebP, до 5 МБ)
        <input type="file" name="photos[]" accept="image/*" multiple>
    </label>
    <?php if (!empty($images)): ?>
        <div class="res-meta">Уже загружено:</div>
        <?php foreach ($images as $img): ?>
            <img src="<?= View::e(rtrim((string) \SkazResidents\Config::get('uploads_url'), '/')) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
        <?php endforeach; ?>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить и отправить на проверку' : 'Отправить на проверку' ?></button>
</form>
```

- [ ] **Step 3: Маршруты**

```php
use SkazResidents\Controller\DiaryController;
$diary = new DiaryController();
$router->get('/poselenie/dnevnik/novaya', [$diary, 'showCreate']);
$router->post('/poselenie/dnevnik/novaya', [$diary, 'create']);
$router->get('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'showEdit']);
$router->post('/poselenie/dnevnik/{id}/redaktirovat', [$diary, 'update']);
$router->get('/poselenie/dnevnik/{id}/udalit', [$diary, 'delete']);
```

- [ ] **Step 4: Проверка**

Run: `php -l src/Controller/DiaryController.php && php vendor/bin/phpunit`
Expected: нет ошибок, тесты зелёные.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Controller/DiaryController.php residents/src/templates/diary residents/public/index.php
git commit -m "feat(residents): CRUD дневника с загрузкой фото"
```

---

## Фаза 7 — Товары/услуги

### Task 17: CRUD товаров и услуг

**Files:**
- Create: `residents/src/Controller/ProductController.php`
- Create: `residents/src/templates/product/form.php`
- Modify: `residents/public/index.php`

Аналог дневника, поля: `title`, `description`, `price` (необязательно), `contact`. Пустая цена → NULL («по договорённости»).

- [ ] **Step 1: ProductController**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, Validator, View, Config, Upload};
use SkazResidents\Repository\{ProductRepository, ImageRepository};

final class ProductController
{
    public function __construct(
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function showCreate(): void
    {
        Auth::requireLogin();
        View::render('product/form', ['product' => null, 'images' => [], 'errors' => []], 'Новый товар/услуга');
    }

    public function create(): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        [$data, $errors] = $this->validate();
        if ($errors) {
            View::render('product/form', ['product' => $data, 'images' => [], 'errors' => $errors], 'Новый товар/услуга');
            return;
        }
        $id = $this->products->create(Auth::id(), $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'));
        $this->handleUploads($id);
        Flash::set('success', 'Отправлено на проверку редактору.');
        header('Location: /poselenie/');
    }

    public function showEdit(array $params): void
    {
        Auth::requireLogin();
        $product = $this->ownedOr404((int) $params['id']);
        View::render('product/form', [
            'product' => $product,
            'images' => $this->images->listFor('product', (int) $product['id']),
            'errors' => [],
        ], 'Редактирование товара');
    }

    public function update(array $params): void
    {
        Auth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
        $product = $this->ownedOr404((int) $params['id']);
        [$data, $errors] = $this->validate();
        if ($errors) {
            $data['id'] = $product['id'];
            View::render('product/form', ['product' => $data, 'images' => $this->images->listFor('product', (int) $product['id']), 'errors' => $errors], 'Редактирование товара');
            return;
        }
        $this->products->update((int) $product['id'], $data['title'], $data['description'], $data['price'], $data['contact'], date('Y-m-d H:i:s'));
        $this->handleUploads((int) $product['id']);
        Flash::set('success', 'Изменения отправлены на повторную проверку.');
        header('Location: /poselenie/');
    }

    public function delete(array $params): void
    {
        Auth::requireLogin();
        $product = $this->ownedOr404((int) $params['id']);
        $this->images->deleteFor('product', (int) $product['id']);
        $this->products->delete((int) $product['id']);
        Flash::set('success', 'Удалено.');
        header('Location: /poselenie/');
    }

    /** @return array{0:array<string,?string>,1:array<string,string>} */
    private function validate(): array
    {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $errors = [];
        if (!Validator::length($title, 2, 200)) { $errors['title'] = 'Название: 2–200 символов.'; }
        if (!Validator::required($desc)) { $errors['description'] = 'Опишите товар или услугу.'; }
        if (!Validator::length($contact, 3, 200)) { $errors['contact'] = 'Укажите, как с вами связаться.'; }
        return [[
            'title' => $title, 'description' => $desc,
            'price' => $price === '' ? null : $price, 'contact' => $contact,
        ], $errors];
    }

    private function handleUploads(int $ownerId): void
    {
        if (empty($_FILES['photos'])) { return; }
        $dir = Config::get('uploads_dir');
        $f = $_FILES['photos'];
        $files = is_array($f['name'])
            ? array_map(fn($i) => [
                'name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                'error' => $f['error'][$i], 'size' => $f['size'][$i],
            ], array_keys($f['name']))
            : [$f];
        $sort = count($this->images->listFor('product', $ownerId));
        foreach ($files as $file) {
            [$name, $err] = Upload::saveImage($file, $dir);
            if ($name !== null) { $this->images->add('product', $ownerId, $name, $sort++); }
            elseif ($err !== null) { Flash::set('error', $err); }
        }
    }

    private function ownedOr404(int $id): array
    {
        $p = $this->products->findById($id);
        if (!$p || (int) $p['family_id'] !== Auth::id()) {
            http_response_code(404);
            View::render('public/notfound', [], 'Товар не найден');
            exit;
        }
        return $p;
    }
}
```

- [ ] **Step 2: templates/product/form.php**

```php
<?php use SkazResidents\{Csrf, View};
$isEdit = !empty($product['id']);
$action = $isEdit ? '/poselenie/yarmarka/' . (int) $product['id'] . '/redaktirovat' : '/poselenie/yarmarka/novyy';
?>
<h1><?= $isEdit ? 'Редактирование' : 'Новый товар или услуга' ?></h1>
<form class="res-form" method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Название
        <input type="text" name="title" value="<?= View::e($product['title'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['title'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['title']) ?></div><?php endif; ?>
    <label>Описание
        <textarea name="description" required><?= View::e($product['description'] ?? '') ?></textarea>
    </label>
    <?php if (isset($errors['description'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['description']) ?></div><?php endif; ?>
    <label>Цена (можно оставить пустым — «по договорённости»)
        <input type="text" name="price" value="<?= View::e($product['price'] ?? '') ?>">
    </label>
    <label>Как связаться (телефон, мессенджер и т.п.)
        <input type="text" name="contact" value="<?= View::e($product['contact'] ?? '') ?>" required>
    </label>
    <?php if (isset($errors['contact'])): ?><div class="res-flash res-flash--error"><?= View::e($errors['contact']) ?></div><?php endif; ?>
    <label>Фотографии (JPEG/PNG/WebP, до 5 МБ)
        <input type="file" name="photos[]" accept="image/*" multiple>
    </label>
    <?php if (!empty($images)): ?>
        <div class="res-meta">Уже загружено:</div>
        <?php foreach ($images as $img): ?>
            <img src="<?= View::e(rtrim((string) \SkazResidents\Config::get('uploads_url'), '/')) ?>/<?= View::e($img['path']) ?>" alt="" style="max-width:120px">
        <?php endforeach; ?>
    <?php endif; ?>
    <button class="res-btn" type="submit"><?= $isEdit ? 'Сохранить и отправить на проверку' : 'Отправить на проверку' ?></button>
</form>
```

- [ ] **Step 3: Маршруты**

```php
use SkazResidents\Controller\ProductController;
$product = new ProductController();
$router->get('/poselenie/yarmarka/novyy', [$product, 'showCreate']);
$router->post('/poselenie/yarmarka/novyy', [$product, 'create']);
$router->get('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'showEdit']);
$router->post('/poselenie/yarmarka/{id}/redaktirovat', [$product, 'update']);
$router->get('/poselenie/yarmarka/{id}/udalit', [$product, 'delete']);
```

**Порядок маршрутов:** эти `/poselenie/yarmarka/...` регистрируются до публичного `/yarmarka/...` — конфликтов нет (разные префиксы). Но внутри группы `/poselenie/yarmarka/{id}/...` не пересекается с `/poselenie/yarmarka/novyy`, потому что `novyy` — точный путь без параметра, а `{id}` требует сегмент перед `/redaktirovat`. Регистрация `novyy` в списке роутера идёт первой — при совпадении вернётся она.

- [ ] **Step 4: Проверка**

Run: `php -l src/Controller/ProductController.php && php vendor/bin/phpunit`
Expected: нет ошибок, тесты зелёные.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Controller/ProductController.php residents/src/templates/product residents/public/index.php
git commit -m "feat(residents): CRUD товаров и услуг"
```

---

## Фаза 8 — Модерация

### Task 18: Панель редактора

**Files:**
- Create: `residents/src/Controller/ModerationController.php`
- Create: `residents/src/templates/moderation/index.php`
- Modify: `residents/public/index.php`

Возможности: очередь заявок на регистрацию (одобрить/отклонить), очередь записей и товаров (одобрить/отклонить с причиной), ручной сброс пароля семье. Все действия — POST с CSRF, доступ только роли `editor`. При одобрении/отклонении — письмо семье (fail-open).

- [ ] **Step 1: ModerationController**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Csrf, Flash, View, Config, Mailer};
use SkazResidents\Repository\{FamilyRepository, DiaryRepository, ProductRepository};

final class ModerationController
{
    public function __construct(
        private FamilyRepository $families = new FamilyRepository(),
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository()
    ) {}

    public function index(): void
    {
        Auth::requireEditor();
        View::render('moderation/index', [
            'pendingFamilies' => $this->families->listByStatus('pending'),
            'activeFamilies'  => $this->families->listByStatus('active'),
            'pendingEntries'  => $this->diary->listPending(),
            'pendingProducts' => $this->products->listPending(),
        ], 'Модерация');
    }

    public function approveFamily(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $this->families->approve($id, date('Y-m-d H:i:s'));
            $this->mail($family['email'], 'Заявка одобрена — Сказочный Край',
                "Здравствуйте!\n\nВаша заявка одобрена. Теперь вы можете войти в кабинет жителя:\n" . Config::get('base_url') . "/poselenie/vhod");
            Flash::set('success', 'Семья одобрена.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectFamily(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $this->families->setStatus($id, 'blocked');
            Flash::set('info', 'Заявка отклонена (аккаунт заблокирован).');
        }
        header('Location: /poselenie/moderation');
    }

    public function resetPassword(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $family = $this->families->findById($id);
        if ($family) {
            $newPass = bin2hex(random_bytes(4)); // 8 hex-символов
            $this->families->updatePassword($id, Auth::hash($newPass));
            Flash::set('success', "Новый пароль для «{$family['name']}»: $newPass — передайте его семье.");
        }
        header('Location: /poselenie/moderation');
    }

    public function approveEntry(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $entry = $this->diary->findById($id);
        if ($entry) {
            $this->diary->approve($id, date('Y-m-d H:i:s'));
            $this->notifyOwnerDiary($entry, 'опубликована', null);
            Flash::set('success', 'Запись опубликована.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectEntry(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $entry = $this->diary->findById($id);
        if ($entry) {
            $this->diary->reject($id, $reason !== '' ? $reason : 'Без указания причины');
            $this->notifyOwnerDiary($entry, 'отклонена', $reason);
            Flash::set('info', 'Запись отклонена.');
        }
        header('Location: /poselenie/moderation');
    }

    public function approveProduct(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $p = $this->products->findById($id);
        if ($p) {
            $this->products->approve($id, date('Y-m-d H:i:s'));
            $this->notifyOwnerProduct($p, 'опубликован', null);
            Flash::set('success', 'Товар опубликован.');
        }
        header('Location: /poselenie/moderation');
    }

    public function rejectProduct(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $p = $this->products->findById($id);
        if ($p) {
            $this->products->reject($id, $reason !== '' ? $reason : 'Без указания причины');
            $this->notifyOwnerProduct($p, 'отклонён', $reason);
            Flash::set('info', 'Товар отклонён.');
        }
        header('Location: /poselenie/moderation');
    }

    private function guard(): void
    {
        Auth::requireEditor();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }

    private function notifyOwnerDiary(array $entry, string $verb, ?string $reason): void
    {
        $family = $this->families->findById((int) $entry['family_id']);
        if (!$family) { return; }
        $body = "Здравствуйте!\n\nВаша запись дневника «{$entry['title']}» $verb.";
        if ($reason) { $body .= "\nПричина: $reason\nВы можете исправить и отправить снова в личном кабинете."; }
        $this->mail($family['email'], "Дневник: запись $verb — Сказочный Край", $body);
    }

    private function notifyOwnerProduct(array $product, string $verb, ?string $reason): void
    {
        $family = $this->families->findById((int) $product['family_id']);
        if (!$family) { return; }
        $body = "Здравствуйте!\n\nВаш товар/услуга «{$product['title']}» $verb.";
        if ($reason) { $body .= "\nПричина: $reason\nВы можете исправить и отправить снова в личном кабинете."; }
        $this->mail($family['email'], "Ярмарка: объявление $verb — Сказочный Край", $body);
    }

    private function mail(string $to, string $subject, string $body): void
    {
        try { Mailer::send($to, $subject, $body); }
        catch (\Throwable $e) { error_log('moderation mail failed: ' . $e->getMessage()); }
    }
}
```

- [ ] **Step 2: templates/moderation/index.php**

```php
<?php use SkazResidents\{Csrf, View}; ?>
<h1>Модерация</h1>

<section>
    <h2>Заявки на регистрацию (<?= count($pendingFamilies) ?>)</h2>
    <?php foreach ($pendingFamilies as $f): ?>
        <div class="res-card">
            <strong><?= View::e($f['name']) ?></strong> — <?= View::e($f['email']) ?>
            <div>
                <form method="post" action="/poselenie/moderation/family/approve" style="display:inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button class="res-btn" type="submit">Одобрить</button>
                </form>
                <form method="post" action="/poselenie/moderation/family/reject" style="display:inline">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingFamilies): ?><p class="res-meta">Новых заявок нет.</p><?php endif; ?>
</section>

<section>
    <h2>Записи дневника на проверке (<?= count($pendingEntries) ?>)</h2>
    <?php foreach ($pendingEntries as $e): ?>
        <div class="res-card">
            <strong><?= View::e($e['title']) ?></strong>
            <span class="res-meta">— <?= View::e($e['family_name']) ?></span>
            <p><?= nl2br(View::e($e['body'])) ?></p>
            <form method="post" action="/poselenie/moderation/entry/approve" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <button class="res-btn" type="submit">Опубликовать</button>
            </form>
            <form method="post" action="/poselenie/moderation/entry/reject" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                <input type="text" name="reason" placeholder="причина отклонения">
                <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingEntries): ?><p class="res-meta">Нет записей на проверке.</p><?php endif; ?>
</section>

<section>
    <h2>Товары/услуги на проверке (<?= count($pendingProducts) ?>)</h2>
    <?php foreach ($pendingProducts as $p): ?>
        <div class="res-card">
            <strong><?= View::e($p['title']) ?></strong>
            <span class="res-meta">— <?= View::e($p['family_name']) ?></span>
            <p><?= nl2br(View::e($p['description'])) ?></p>
            <p class="res-meta">Цена: <?= $p['price'] !== null ? View::e($p['price']) : 'по договорённости' ?> · Контакт: <?= View::e($p['contact']) ?></p>
            <form method="post" action="/poselenie/moderation/product/approve" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="res-btn" type="submit">Опубликовать</button>
            </form>
            <form method="post" action="/poselenie/moderation/product/reject" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="text" name="reason" placeholder="причина отклонения">
                <button class="res-btn res-btn--ghost" type="submit">Отклонить</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$pendingProducts): ?><p class="res-meta">Нет товаров на проверке.</p><?php endif; ?>
</section>

<section>
    <h2>Семьи (сброс пароля)</h2>
    <?php foreach ($activeFamilies as $f): ?>
        <div class="res-card">
            <?= View::e($f['name']) ?> — <?= View::e($f['email']) ?>
            <form method="post" action="/poselenie/moderation/family/reset-password" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button class="res-btn res-btn--ghost" type="submit" onclick="return confirm('Сбросить пароль этой семье?')">Сбросить пароль</button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
```

- [ ] **Step 3: Маршруты**

```php
use SkazResidents\Controller\ModerationController;
$mod = new ModerationController();
$router->get('/poselenie/moderation', [$mod, 'index']);
$router->post('/poselenie/moderation/family/approve', [$mod, 'approveFamily']);
$router->post('/poselenie/moderation/family/reject', [$mod, 'rejectFamily']);
$router->post('/poselenie/moderation/family/reset-password', [$mod, 'resetPassword']);
$router->post('/poselenie/moderation/entry/approve', [$mod, 'approveEntry']);
$router->post('/poselenie/moderation/entry/reject', [$mod, 'rejectEntry']);
$router->post('/poselenie/moderation/product/approve', [$mod, 'approveProduct']);
$router->post('/poselenie/moderation/product/reject', [$mod, 'rejectProduct']);
```

- [ ] **Step 4: Проверка**

Run: `php -l src/Controller/ModerationController.php && php vendor/bin/phpunit`
Expected: нет ошибок, тесты зелёные.

- [ ] **Step 5: Commit**

```bash
git add residents/src/Controller/ModerationController.php residents/src/templates/moderation residents/public/index.php
git commit -m "feat(residents): панель модерации редактора"
```

---

## Фаза 9 — Публичные ленты

### Task 19: Публичные страницы «Дневники поместий» и «Ярмарка»

**Files:**
- Create: `residents/src/Controller/PublicController.php`
- Create: `residents/src/templates/public/diary_list.php`
- Create: `residents/src/templates/public/diary_show.php`
- Create: `residents/src/templates/public/market_list.php`
- Create: `residents/src/templates/public/market_show.php`
- Modify: `residents/public/index.php`

- [ ] **Step 1: PublicController**

```php
<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{View, Config};
use SkazResidents\Repository\{DiaryRepository, ProductRepository, ImageRepository};

final class PublicController
{
    private const PER_PAGE = 10;

    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    public function diaryList(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $entries = $this->diary->listPublished(self::PER_PAGE, $offset);
        foreach ($entries as &$e) {
            $e['images'] = $this->images->listFor('entry', (int) $e['id']);
        }
        unset($e);
        View::render('public/diary_list', [
            'entries' => $entries,
            'page' => $page,
            'total' => $this->diary->countPublished(),
            'perPage' => self::PER_PAGE,
        ], 'Дневники поместий');
    }

    public function diaryShow(array $params): void
    {
        $entry = $this->diary->findPublishedById((int) $params['id']);
        if (!$entry) { http_response_code(404); View::render('public/notfound', [], 'Запись не найдена'); return; }
        $entry['images'] = $this->images->listFor('entry', (int) $entry['id']);
        View::render('public/diary_show', ['entry' => $entry], $entry['title']);
    }

    public function marketList(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $items = $this->products->listPublished(self::PER_PAGE, $offset);
        foreach ($items as &$p) {
            $p['images'] = $this->images->listFor('product', (int) $p['id']);
        }
        unset($p);
        View::render('public/market_list', [
            'items' => $items,
            'page' => $page,
            'total' => $this->products->countPublished(),
            'perPage' => self::PER_PAGE,
        ], 'Ярмарка');
    }

    public function marketShow(array $params): void
    {
        $p = $this->products->findPublishedById((int) $params['id']);
        if (!$p) { http_response_code(404); View::render('public/notfound', [], 'Товар не найден'); return; }
        $p['images'] = $this->images->listFor('product', (int) $p['id']);
        View::render('public/market_show', ['product' => $p], $p['title']);
    }

    public static function uploadsUrl(): string
    {
        return rtrim((string) Config::get('uploads_url'), '/');
    }
}
```

- [ ] **Step 2: templates/public/diary_list.php**

```php
<?php use SkazResidents\{View}; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<h1>Дневники поместий</h1>
<p class="res-meta">Как живут семьи поселения «Сказочный Край».</p>
<?php if (!$entries): ?><p>Пока нет опубликованных записей.</p><?php endif; ?>
<?php foreach ($entries as $e): ?>
    <article class="res-card">
        <h2><a href="/dnevniki-pomestiy/<?= (int) $e['id'] ?>"><?= View::e($e['title']) ?></a></h2>
        <p class="res-meta"><?= View::e($e['family_name']) ?> · <?= View::e(substr((string) $e['published_at'], 0, 10)) ?></p>
        <?php if (!empty($e['images'])): ?>
            <img src="<?= $u ?>/<?= View::e($e['images'][0]['path']) ?>" alt="">
        <?php endif; ?>
        <p><?= View::e(mb_strimwidth(strip_tags((string) $e['body']), 0, 300, '…')) ?></p>
        <a href="/dnevniki-pomestiy/<?= (int) $e['id'] ?>">Читать целиком →</a>
    </article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
    <nav class="res-meta">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?><strong><?= $i ?></strong><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
```

- [ ] **Step 3: templates/public/diary_show.php**

```php
<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
?>
<article>
    <h1><?= View::e($entry['title']) ?></h1>
    <p class="res-meta"><?= View::e($entry['family_name']) ?> · <?= View::e(substr((string) $entry['published_at'], 0, 10)) ?></p>
    <?php foreach ($entry['images'] as $img): ?>
        <img class="res-card" src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
    <?php endforeach; ?>
    <div><?= nl2br(View::e($entry['body'])) ?></div>
    <p><a href="/dnevniki-pomestiy/">← Ко всем дневникам</a></p>
</article>
```

- [ ] **Step 4: templates/public/market_list.php**

```php
<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
$pages = (int) ceil($total / $perPage);
?>
<h1>Ярмарка</h1>
<p class="res-meta">Товары и услуги семей поселения.</p>
<?php if (!$items): ?><p>Пока нет объявлений.</p><?php endif; ?>
<?php foreach ($items as $p): ?>
    <article class="res-card">
        <h2><a href="/yarmarka/<?= (int) $p['id'] ?>"><?= View::e($p['title']) ?></a></h2>
        <?php if (!empty($p['images'])): ?>
            <img src="<?= $u ?>/<?= View::e($p['images'][0]['path']) ?>" alt="">
        <?php endif; ?>
        <p><?= View::e(mb_strimwidth((string) $p['description'], 0, 240, '…')) ?></p>
        <p class="res-meta">
            <?= $p['price'] !== null ? View::e($p['price']) : 'по договорённости' ?>
            · <?= View::e($p['family_name']) ?>
        </p>
    </article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
    <nav class="res-meta">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <?php if ($i === $page): ?><strong><?= $i ?></strong><?php else: ?><a href="?page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
```

- [ ] **Step 5: templates/public/market_show.php**

```php
<?php use SkazResidents\View; use SkazResidents\Controller\PublicController;
$u = PublicController::uploadsUrl();
?>
<article>
    <h1><?= View::e($product['title']) ?></h1>
    <p class="res-meta"><?= View::e($product['family_name']) ?></p>
    <?php foreach ($product['images'] as $img): ?>
        <img class="res-card" src="<?= $u ?>/<?= View::e($img['path']) ?>" alt="">
    <?php endforeach; ?>
    <div><?= nl2br(View::e($product['description'])) ?></div>
    <p><strong>Цена:</strong> <?= $product['price'] !== null ? View::e($product['price']) : 'по договорённости' ?></p>
    <p><strong>Как связаться:</strong> <?= View::e($product['contact']) ?></p>
    <p><a href="/yarmarka/">← Вся Ярмарка</a></p>
</article>
```

- [ ] **Step 6: Маршруты (публичные)**

```php
use SkazResidents\Controller\PublicController;
$public = new PublicController();
$router->get('/dnevniki-pomestiy', [$public, 'diaryList']);
$router->get('/dnevniki-pomestiy/{id}', [$public, 'diaryShow']);
$router->get('/yarmarka', [$public, 'marketList']);
$router->get('/yarmarka/{id}', [$public, 'marketShow']);
```

- [ ] **Step 7: Проверка**

Run: `php -l src/Controller/PublicController.php && php vendor/bin/phpunit`
Expected: нет ошибок, тесты зелёные.

- [ ] **Step 8: Commit**

```bash
git add residents/src/Controller/PublicController.php residents/src/templates/public residents/public/index.php
git commit -m "feat(residents): публичные ленты «Дневники поместий» и «Ярмарка»"
```

---

## Фаза 10 — Интеграция с сайтом и развёртывание

### Task 20: nginx-конфиг, deploy-скрипт, runbook установки

**Files:**
- Create: `residents/deploy/nginx-residents.conf.example`
- Create: `residents/deploy/deploy.sh`
- Create: `residents/deploy/README.md`

- [ ] **Step 1: nginx-residents.conf.example** — вставка в существующий server-блок `skaz-kray.ru`

```nginx
# --- Раздел жителей поселения (PHP-приложение вне докрута статики) ---
# Вставить внутрь server { } vhost skaz-kray.ru (там же, где location для статики).
# root приложения — /var/www/skaz-residents/public

# Отдача загруженных фото как статики, без выполнения PHP
location /poselenie/uploads/ {
    alias /var/www/skaz-residents/public/uploads/;
    access_log off;
    expires 30d;
}

# Публичные ленты и раздел жителей — во фронт-контроллер PHP
location ^~ /poselenie/ {
    root /var/www/skaz-residents/public;
    try_files $uri /index.php$is_args$args;
}
location = /poselenie { return 301 /poselenie/; }

location = /dnevniki-pomestiy { try_files /nonexistent /poselenie-fc; }
location ^~ /dnevniki-pomestiy/ { try_files /nonexistent /poselenie-fc; }
location ^~ /yarmarka/ { try_files /nonexistent /poselenie-fc; }
location = /yarmarka { try_files /nonexistent /poselenie-fc; }

# Общая точка входа во фронт-контроллер
location = /poselenie-fc {
    internal;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/skaz-residents/public/index.php;
    fastcgi_param REQUEST_URI $request_uri;
}

# PHP внутри /poselenie/
location ~ ^/poselenie/.*\.php$ {
    root /var/www/skaz-residents/public;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/skaz-residents/public/index.php;
}
```

**Примечание:** маршрутизация nginx→PHP тонкая. Ориентир — уже рабочие блоки `/oauth/` и `/editor-auth/` в `skaz-kray_ru_astro` ([[skaz_kray_decap_oauth]], [[skaz_kray_editor_login]]): скопировать их стиль (`rewrite` + `fastcgi_pass` на `php8.3-fpm.sock`) и направить `/poselenie/`, `/dnevniki-pomestiy/`, `/yarmarka/` во фронт-контроллер `/var/www/skaz-residents/public/index.php`, передавая `REQUEST_URI`. Точную форму блоков доводить на сервере вместе с проверкой `nginx -t`. Статические ассеты и `uploads` отдавать напрямую, без PHP. Конфиг nginx в git не хранится — правится на сервере.

- [ ] **Step 2: deploy/deploy.sh** — синхронизация кода на сервер (запускать локально)

```bash
#!/usr/bin/env bash
set -euo pipefail

# Деплой раздела жителей на сервер. Секреты (config.php) и uploads НЕ трогаем.
SERVER="abconsult"                       # ssh-алиас = root@31.128.43.151
DEST="/var/www/skaz-residents"
SRC="$(cd "$(dirname "$0")/.." && pwd)"  # каталог residents/

echo "Синхронизация кода в $SERVER:$DEST ..."
rsync -az --delete \
  --exclude 'config/config.php' \
  --exclude 'public/uploads/' \
  --exclude 'vendor/' \
  --exclude '.phpunit.cache/' \
  --exclude 'tests/' \
  "$SRC/" "$SERVER:$DEST/"

echo "Composer install (--no-dev) на сервере ..."
ssh "$SERVER" "cd $DEST && composer install --no-dev --optimize-autoloader"

echo "Права на uploads ..."
ssh "$SERVER" "mkdir -p $DEST/public/uploads && chown -R www-data:www-data $DEST/public/uploads $DEST/vendor"

echo "Готово. Проверьте https://skaz-kray.ru/poselenie/vhod"
```

- [ ] **Step 3: deploy/README.md** — runbook первичной установки на сервере

````markdown
# Установка раздела жителей на сервере

Сервер: `ssh abconsult` (root@31.128.43.151), тот же, что и статика skaz-kray.ru.

## 1. База данных
```sql
CREATE DATABASE skazkray_residents CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'skaz_residents'@'127.0.0.1' IDENTIFIED BY '<СИЛЬНЫЙ_ПАРОЛЬ>';
GRANT SELECT, INSERT, UPDATE, DELETE ON skazkray_residents.* TO 'skaz_residents'@'127.0.0.1';
FLUSH PRIVILEGES;
```
Накатить схему:
```
mysql skazkray_residents < /var/www/skaz-residents/config/schema.sql
```

## 2. Конфиг (секреты, вне git)
```
cp /var/www/skaz-residents/config/config.example.php /var/www/skaz-residents/config/config.php
chmod 640 /var/www/skaz-residents/config/config.php
chown root:www-data /var/www/skaz-residents/config/config.php
# заполнить: db.pass, smtp.* (ящик на skaz-kray.ru), при необходимости uploads_dir
```

## 3. Редактор-модератор
Завести аккаунт редактора (через регистрацию на сайте, затем в БД):
```sql
UPDATE families SET status='active', role='editor' WHERE email='<email редактора>';
```

## 4. nginx
Вставить блоки из `deploy/nginx-residents.conf.example` в vhost `skaz-kray_ru_astro`
(ориентир — рабочие блоки /oauth/ и /editor-auth/), затем:
```
nginx -t && systemctl reload nginx
```

## 5. Автодеплой статики не конфликтует
Приложение живёт в `/var/www/skaz-residents/`, вне докрута статики
(`/var/www/new.skaz-kray.ru/html`). `skaz-kray-autodeploy.sh` его не трогает —
дополнительных `--exclude` не требуется.

## 6. Обновление кода
Локально: `bash residents/deploy/deploy.sh`
````

- [ ] **Step 4: Сделать deploy.sh исполняемым и проверить синтаксис**

Run: `bash -n residents/deploy/deploy.sh`
Expected: без вывода (синтаксис корректен).

- [ ] **Step 5: Commit**

```bash
git add residents/deploy
git commit -m "chore(residents): nginx-конфиг, deploy-скрипт и runbook установки"
```

---

### Task 21: Пункты меню в шапке статического сайта

**Files:**
- Modify: `src/components/Header.astro` (точное имя/путь компонента шапки проверить)

- [ ] **Step 1: Найти компонент шапки и пункты меню**

Run: `grep -rn "Контакты" src/components/ src/layouts/ 2>/dev/null | head`
Expected: строка(и) с пунктами навигации — это целевой файл.

- [ ] **Step 2: Добавить пункты навигации**

В список ссылок навигации добавить (сохраняя разметку существующих пунктов — тег, классы, порядок):

```html
<a href="/dnevniki-pomestiy/">Дневники поместий</a>
<a href="/yarmarka/">Ярмарка</a>
<a href="/poselenie/vhod">Кабинет жителя</a>
```

Точную обёртку (`<li>`, классы) скопировать с соседнего пункта меню в этом же файле — разметка навигации у RT-Theme-порта своя, поэтому равняться на фактический код компонента, а не на этот пример.

- [ ] **Step 3: Собрать сайт**

Run: `npm run build`
Expected: сборка проходит, новые пункты присутствуют в выводе.

- [ ] **Step 4: Commit**

```bash
git add src/components/Header.astro
git commit -m "feat(site): пункты меню — Дневники поместий, Ярмарка, Кабинет жителя"
```

---

## Фаза 11 — Сквозная проверка

### Task 22: Ручной прогон всех потоков в браузере

**Files:** нет (проверка развёрнутого приложения).

Развернуть по `deploy/README.md` (сервер) или локально (`php -S 127.0.0.1:8080 -t residents/public` с локальной MariaDB и заполненным `config.php`). Пройти и отметить каждый пункт:

- [ ] **Регистрация:** `/poselenie/register` — заявка создаётся, статус `pending`, войти нельзя («Заявка ещё не одобрена»).
- [ ] **Одобрение семьи:** под редактором `/poselenie/moderation` — одобрить заявку; приходит письмо; семья входит.
- [ ] **Отклонение заявки:** вторая заявка — «Отклонить» → статус `blocked`, вход выдаёт сообщение о блокировке.
- [ ] **Дневник — создание:** в кабинете «Новая запись» с фото → статус «на проверке», в публичной ленте пока нет.
- [ ] **Дневник — публикация:** редактор «Опубликовать» → запись видна на `/dnevniki-pomestiy/`, подписана семьёй, фото открывается; семье пришло письмо.
- [ ] **Дневник — отклонение:** отклонить с причиной → в кабинете виден статус «отклонено» и причина; правка → снова «на проверке».
- [ ] **Правка опубликованной записи** → возвращается на модерацию (исчезает из публичной ленты до повторного одобрения).
- [ ] **Ярмарка:** создание товара (с пустой ценой → «по договорённости»), публикация, отображение на `/yarmarka/`, страница товара с контактом.
- [ ] **Удаление** своей записи и товара — исчезают сразу, фото убираются.
- [ ] **Восстановление пароля:** `/poselenie/vosstanovit` → письмо со ссылкой → задать новый пароль → вход с новым.
- [ ] **Ручной сброс пароля** редактором в панели — выдаётся новый пароль, вход работает.
- [ ] **Доступы:** незалогиненный не попадает в `/poselenie/` (редирект на вход); житель (не редактор) получает 403 на `/poselenie/moderation`; нельзя открыть чужую запись на редактирование (404).
- [ ] **Меню сайта:** пункты «Дневники поместий», «Ярмарка», «Кабинет жителя» ведут куда нужно и оформлены как остальной сайт.

- [ ] **Итоговый прогон юнит-тестов**

Run: `cd residents && php vendor/bin/phpunit`
Expected: все тесты зелёные.

- [ ] **Commit (если по ходу были правки)**

```bash
git commit -am "fix(residents): правки по итогам сквозной проверки"
```

---

## Итог

По завершении всех задач на skaz-kray.ru работает раздел для жителей: вход по email/паролю на семью (заявка → одобрение редактором), личный кабинет с дневником поместья и товарами/услугами, модерация редактором с уведомлениями по email, публичные ленты «Дневники поместий» и «Ярмарка» в оформлении сайта. Приложение изолировано от статики (свой каталог, своя БД), секреты вне git, автодеплой статики его не затрагивает.
