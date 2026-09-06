<?php
declare(strict_types=1);
namespace SkazResidents;

use SkazResidents\Repository\SectionSettingsRepository;

/**
 * Разделы приложения, которые редактор может включать/выключать (плитки на
 * главной /poselenie/app + пункты меню). Ключ раздела совпадает с сегментом
 * его URL под /poselenie/. Список — единственный источник правды по разделам;
 * состояние «вкл/выкл» хранит SectionSettingsRepository (таблица app_sections).
 *
 * «Наше поместье» (/poselenie/moye-pomestie) — базовый раздел, в список НЕ входит
 * и выключить его нельзя (isEnabled для неизвестного ключа всегда true).
 */
final class Sections
{
    /** @var array<string,string> ключ раздела => подпись для страницы настроек */
    public const LIST = [
        'dnevniki'    => 'Дневники поместий',
        'instrumenty' => 'Инструменты',
        'knigi'       => 'Книги',
        'poezdki'     => 'Поездки',
        'byudzhet'    => 'Бюджет Общего дома',
        'yarmarka'    => 'Ярмарка',
        'sosedi'      => 'Наши соседи',
    ];

    /** @var array<int,string>|null кэш выключенных ключей на время запроса */
    private static ?array $disabled = null;

    /** Показывать ли раздел. Неизвестный (не из LIST) ключ считается включённым. */
    public static function isEnabled(string $key): bool
    {
        if (self::$disabled === null) {
            self::$disabled = (new SectionSettingsRepository())->disabledKeys();
        }
        return !in_array($key, self::$disabled, true);
    }

    /** Сбросить кэш (после изменения настроек в том же запросе и в тестах). */
    public static function reset(): void
    {
        self::$disabled = null;
    }
}
