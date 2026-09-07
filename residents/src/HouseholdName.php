<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Отображаемое название поместья: «Поместье {Фамилия семьи} ({название})».
 * Фамилия берётся у главы (первого жителя по порядку) и приводится к семейной
 * форме (родительный падеж мн. ч.): Бобков→Бобковых, Кашина→Кашиных,
 * Людоговский→Людоговских, Руденко→Руденко (несклоняемые — как есть).
 *
 * Если estate_name уже записан как «Поместье/Участок/Двор <Фамилия>» — оставляем
 * как есть (в справочнике так у ~половины поместий). Данные не меняются — это
 * только представление.
 */
final class HouseholdName
{
    public static function title(string $estateName, ?string $headFullName): string
    {
        $estate = trim($estateName);
        $low = mb_strtolower($estate, 'UTF-8');
        foreach (['поместье ', 'участок ', 'двор '] as $pfx) {
            if (mb_strpos($low, $pfx) === 0) { return $estate; }   // уже семейная форма
        }

        $surname = '';
        if ($headFullName !== null && trim($headFullName) !== '') {
            $surname = self::familyForm(self::surnameToken($headFullName));
        }

        if ($surname === '') {
            // Нет главы (коммунальные/свободные участки) — название как есть.
            return $estate !== '' ? $estate : 'Поместье';
        }
        if ($estate === '') {
            return 'Поместье ' . $surname;
        }
        return 'Поместье ' . $surname . ' (' . $estate . ')';
    }

    /**
     * Выбор слова-фамилии из ФИО. Обычно фамилия идёт первой («Фамилия Имя»),
     * но часть записей в порядке «Имя Фамилия» — если первое слово не похоже на
     * фамилию, а второе похоже, берём второе (напр. «Юрий Моисеенко» → «Моисеенко»).
     */
    private static function surnameToken(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $t0 = (string) ($parts[0] ?? '');
        $t1 = (string) ($parts[1] ?? '');
        if ($t1 !== '' && !self::looksLikeSurname($t0) && self::looksLikeSurname($t1)) {
            return $t1;
        }
        return $t0;
    }

    private static function looksLikeSurname(string $t): bool
    {
        return (bool) preg_match('/(ов|ев|ёв|ин|ын|ский|цкий|ской|цкой|ская|цкая|ова|ева|ёва|ина|ына|енко|енка|ко|ук|юк|ич|ыч)$/ui', $t);
    }

    /** Фамилия → семейная форма (родительный мн. ч.). Несклоняемые — без изменений. */
    public static function familyForm(string $surname): string
    {
        $s = trim($surname);
        if ($s === '') { return ''; }

        $ends = static fn(string $suf): bool => mb_substr($s, -mb_strlen($suf), null, 'UTF-8') === $suf;
        $swap = static fn(string $suf, string $new): string =>
            mb_substr($s, 0, mb_strlen($s) - mb_strlen($suf), 'UTF-8') . $new;

        // Женские адъективные и на -ова/-ина → семейная (мужская мн.) форма.
        if ($ends('ская')) { return $swap('ская', 'ских'); }
        if ($ends('цкая')) { return $swap('цкая', 'цких'); }
        if ($ends('ова'))  { return $swap('ова', 'овых'); }
        if ($ends('ёва'))  { return $swap('ёва', 'ёвых'); }
        if ($ends('ева'))  { return $swap('ева', 'евых'); }
        if ($ends('ина'))  { return $swap('ина', 'иных'); }
        if ($ends('ына'))  { return $swap('ына', 'ыных'); }
        // Мужские адъективные.
        if ($ends('ский')) { return $swap('ский', 'ских'); }
        if ($ends('цкий')) { return $swap('цкий', 'цких'); }
        // Мужские на -ов/-ев/-ин.
        if ($ends('ов'))   { return $swap('ов', 'овых'); }
        if ($ends('ёв'))   { return $swap('ёв', 'ёвых'); }
        if ($ends('ев'))   { return $swap('ев', 'евых'); }
        if ($ends('ин'))   { return $swap('ин', 'иных'); }
        if ($ends('ын'))   { return $swap('ын', 'ыных'); }
        // Несклоняемые (Руденко, Шульженко, Беззубенко, Мазепа, Душак, …) — как есть.
        return $s;
    }
}
