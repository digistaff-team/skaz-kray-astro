<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Flash, Sections};

/**
 * Гард выключенного раздела. Админ выключает раздел на странице «Разделы», и до
 * сих пор это лишь убирало плитку и пункт меню — прямая ссылка продолжала
 * работать у всех, кто её сохранил или получил в анонсе бота. Теперь выключенный
 * раздел отвечает возвратом на главную приложения.
 *
 * Ключ — из Sections::LIST (совпадает с сегментом URL: knigi, instrumenty, …).
 * Неизвестный ключ считается включённым, так что базовые разделы вроде «Нашего
 * поместья» гард не задевает.
 */
trait RequiresSection
{
    private function requireSection(string $key): void
    {
        if (Sections::isEnabled($key)) { return; }
        Flash::set('info', 'Раздел «' . (Sections::LIST[$key] ?? $key) . '» сейчас отключён в поселении.');
        header('Location: /poselenie/app');
        exit;
    }
}
