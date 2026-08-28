<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
// Тестам нужен $_SESSION как обычный массив (без реальной сессии)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $GLOBALS['_SESSION'] = $_SESSION ?? [];
}

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
