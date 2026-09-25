<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Учёт SQL-миграций: порядок — config/migrations.txt, применённые — таблица
 * schema_migrations. Здесь только чистая логика (разбор списка, что осталось
 * накатить); сам накат и работа с БД — bin/migrate.php.
 */
final class Migrations
{
    /**
     * Имена файлов из списка по порядку: без пустых строк и комментариев (#).
     * @return list<string>
     */
    public static function manifest(string $text): array
    {
        $names = [];
        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            $names[] = $line;
        }
        return $names;
    }

    /**
     * Ещё не применённые — в порядке списка.
     * @param list<string> $manifest
     * @param list<string> $applied
     * @return list<string>
     */
    public static function pending(array $manifest, array $applied): array
    {
        $done = array_flip($applied);
        return array_values(array_filter($manifest, static fn(string $n): bool => !isset($done[$n])));
    }

    /**
     * Список до указанного файла включительно — для baseline на базе, где эти
     * миграции уже накатывали вручную. Нет такого файла в списке — исключение.
     * @param list<string> $manifest
     * @return list<string>
     */
    public static function upTo(array $manifest, string $last): array
    {
        $pos = array_search($last, $manifest, true);
        if ($pos === false) {
            throw new \InvalidArgumentException("«{$last}» нет в config/migrations.txt");
        }
        return array_slice($manifest, 0, $pos + 1);
    }

    /**
     * Расхождения списка с файлами: [нет в списке, нет файла, повторы].
     * @param list<string> $manifest
     * @param list<string> $files имена *.sql в config/
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    public static function problems(array $manifest, array $files): array
    {
        $dups = array_keys(array_filter(array_count_values($manifest), static fn(int $c): bool => $c > 1));
        return [
            array_values(array_diff($files, $manifest)),
            array_values(array_diff($manifest, $files)),
            $dups,
        ];
    }
}
