<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\Auth;

/**
 * Старый «личный кабинет» жителя сведён к домашнему экрану приложения
 * (/poselenie/app): один дом-хаб, без дубля. Управление своими записями
 * дневника переехало на страницу «Дневник» (/poselenie/dnevniki), товарами —
 * в «Мою витрину» (/poselenie/yarmarka/moya). Маршрут /poselenie сохраняется
 * как редирект — для старых закладок и ссылок.
 */
final class CabinetController
{
    public function index(): void
    {
        Auth::requireLogin();
        header('Location: /poselenie/app');
    }
}
