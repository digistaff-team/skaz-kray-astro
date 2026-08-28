<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
// Тестам нужен $_SESSION как обычный массив (без реальной сессии)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $GLOBALS['_SESSION'] = $_SESSION ?? [];
}
