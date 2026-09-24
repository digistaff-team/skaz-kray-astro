<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
// Тестам нужен $_SESSION как обычный массив (без реальной сессии)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $GLOBALS['_SESSION'] = $_SESSION ?? [];
}

// Хелперы шаблонов (склонения, ссылки на фото): нужны тестам, которые рендерят
// разметку. Полный src/bootstrap.php сюда не годится — он читает config/.env,
// стартует сессию и подключается к боевой БД.
require __DIR__ . '/../src/helpers.php';

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
    // Свежая база — свежие кеши на время «запроса»: иначе тест мог бы унаследовать
    // от предыдущего выключенные разделы или запомненный список «Моих дел».
    \SkazResidents\Sections::reset();
    \SkazResidents\Service\MyTasks::reset();
    return $pdo;
}
