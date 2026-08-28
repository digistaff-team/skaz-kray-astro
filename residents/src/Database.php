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
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            // Форсируем utf8mb4 на уровне соединения. DSN-параметр charset=utf8mb4
            // применяется не во всех сборках pdo_mysql (в php-fpm на проде — нет,
            // из-за чего кириллица писалась как «?»), а INIT_COMMAND надёжен везде.
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
            }
            self::$pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], $opts);
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
