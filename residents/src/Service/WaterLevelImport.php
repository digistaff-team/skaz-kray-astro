<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Repository\WaterLevelRepository;

/**
 * Заливка прошлых замеров уровня воды в свою историю (water_level_history).
 * Кормится записями вида
 *   {"created_at":"2026-05-31T14:29:25.100Z","water_level":-93.62,"change_24h":888}
 * — такой формат отдают и архив shebsh-water-level (public/history.json), и его
 * эндпоинт /api/history, когда тот жив.
 *
 * Данные чужие и местами битые, поэтому фильтруем:
 *   — уровень вне разумных границ поста (архив AllRivers: минимум −1444 см,
 *     максимум +6003 см) — запись пропускаем;
 *   — суточное изменение больше MAX_CHANGE_CM — обнуляем: прежний LLM-парсер
 *     того проекта писал сюда мусор (в архиве есть change_24h = 888).
 * Запись за уже занятый час перезаписывает его (ключ measured_at), так что
 * повторный импорт безопасен.
 */
final class WaterLevelImport
{
    /** Границы разумного уровня по архиву гидропоста, см от нуля поста. */
    private const MIN_LEVEL_CM = -2000;
    private const MAX_LEVEL_CM = 7000;
    /** Суточное изменение больше этого — мусор источника, обнуляем, см. */
    private const MAX_CHANGE_CM = 500;

    public function __construct(
        private WaterLevelRepository $history = new WaterLevelRepository()
    ) {}

    /**
     * @param array<int,mixed> $records записи архива
     * @return array{added:int,updated:int,skipped:int,notes:array<int,string>}
     */
    public function import(array $records, bool $dryRun = false): array
    {
        $added = $updated = $skipped = 0;
        $notes = [];

        foreach ($records as $i => $rec) {
            $parsed = $this->parse(is_array($rec) ? $rec : [], $i, $notes);
            if ($parsed === null) { $skipped++; continue; }

            [$hour, $level, $change] = $parsed;
            $exists = $this->history->has($hour);
            if (!$dryRun) { $this->history->save($hour, $level, $change); }
            $exists ? $updated++ : $added++;
        }

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * Прочитать источник: локальный файл или URL (архив в репозитории проекта).
     * @return array<int,mixed>|null null — источник недоступен или не JSON-список
     */
    public function load(string $source): ?array
    {
        $raw = preg_match('#^https?://#', $source)
            ? WaterLevel::httpGet($source, 10)
            : (is_readable($source) ? (string) file_get_contents($source) : null);
        if ($raw === null) { return null; }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Запись архива → [час 'Y-m-d H:i:s', уровень см, изменение см] или null.
     * @param array<string,mixed> $rec
     * @param array<int,string> $notes пополняется пояснениями к пропускам и правкам
     * @return array{0:string,1:float,2:float}|null
     */
    private function parse(array $rec, int $index, array &$notes): ?array
    {
        $at = (string) ($rec['created_at'] ?? '');
        $hour = $this->hourOf($at);
        if ($hour === null) {
            $notes[] = "запись #{$index}: не разобрать дату «{$at}» — пропущена";
            return null;
        }

        if (!isset($rec['water_level']) || !is_numeric($rec['water_level'])) {
            $notes[] = "запись #{$index} ({$hour}): нет уровня — пропущена";
            return null;
        }
        $level = (float) $rec['water_level'];
        if ($level < self::MIN_LEVEL_CM || $level > self::MAX_LEVEL_CM) {
            $notes[] = sprintf('запись #%d (%s): уровень %.2f см вне границ поста — пропущена', $index, $hour, $level);
            return null;
        }

        $change = is_numeric($rec['change_24h'] ?? null) ? (float) $rec['change_24h'] : 0.0;
        if (abs($change) > self::MAX_CHANGE_CM) {
            $notes[] = sprintf('запись #%d (%s): суточное изменение %.0f см недостоверно — обнулено', $index, $hour, $change);
            $change = 0.0;
        }

        return [$hour, $level, $change];
    }

    /** ISO-время архива (UTC) → час в зоне сервера, 'Y-m-d H:00:00'. */
    private function hourOf(string $iso): ?string
    {
        if ($iso === '') { return null; }
        try {
            $dt = new \DateTime($iso);
        } catch (\Exception) {
            return null;
        }
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H') . ':00:00';
    }
}
