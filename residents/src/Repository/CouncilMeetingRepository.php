<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\{Database, CouncilData};
use PDO;

/**
 * Ближайшее собрание совета — одна строка (id=1) в council_meeting.
 * Дата/время хранятся структурно (starts_at/ends_at, DATETIME) — форма правит их
 * через нативные datetime-local пикеры. Человекочитаемая строка для показа
 * («7 сентября 2026, 18:00–20:00») собирается автоматически в meeting_date.
 * Если строки нет — дефолт из CouncilData (до наката схемы).
 *
 * agenda хранится текстом (по пункту на строку), наружу отдаётся массивом.
 */
final class CouncilMeetingRepository
{
    /** Месяцы в родительном падеже для человекочитаемой даты. */
    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /**
     * @return array{date:string,place:string,dutyChair:string,dutySecretary:string,
     *   agenda:array<int,string>,startsAt:string,endsAt:string}
     */
    public function get(): array
    {
        $row = $this->db->query('SELECT * FROM council_meeting WHERE id = 1')->fetch();
        if (!$row) {
            return CouncilData::nextMeeting() + ['startsAt' => '', 'endsAt' => ''];
        }
        return [
            'date'          => (string) $row['meeting_date'],
            'place'         => (string) $row['place'],
            'dutyChair'     => (string) $row['duty_chair'],
            'dutySecretary' => (string) $row['duty_secretary'],
            'agenda'        => self::splitAgenda((string) ($row['agenda'] ?? '')),
            'startsAt'      => self::toInput($row['starts_at'] ?? null),
            'endsAt'        => self::toInput($row['ends_at'] ?? null),
        ];
    }

    /**
     * $startsAt/$endsAt — в формате БД ('Y-m-d H:i:s') или null. Человекочитаемая
     * meeting_date собирается здесь же.
     */
    public function update(?string $startsAt, ?string $endsAt, string $place, string $dutyChair, string $dutySecretary, string $agenda): void
    {
        $agenda = trim(str_replace("\r\n", "\n", $agenda));
        $display = self::formatDisplay($startsAt, $endsAt);
        $st = $this->db->prepare(
            'INSERT INTO council_meeting (id, meeting_date, starts_at, ends_at, place, duty_chair, duty_secretary, agenda)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                meeting_date = VALUES(meeting_date), starts_at = VALUES(starts_at), ends_at = VALUES(ends_at),
                place = VALUES(place), duty_chair = VALUES(duty_chair),
                duty_secretary = VALUES(duty_secretary), agenda = VALUES(agenda)'
        );
        $st->execute([$display, $startsAt, $endsAt, $place, $dutyChair, $dutySecretary, $agenda]);
    }

    /** Обновить только подпись дежурного председателя в карточке собрания. */
    public function setDutyChairName(string $name): void
    {
        $st = $this->db->prepare('UPDATE council_meeting SET duty_chair = ? WHERE id = 1');
        $st->execute([$name]);
    }

    /** Повестка как единый текст (для textarea в форме). */
    public function agendaText(): string
    {
        $row = $this->db->query('SELECT agenda FROM council_meeting WHERE id = 1')->fetch();
        return $row ? (string) ($row['agenda'] ?? '') : implode("\n", CouncilData::nextMeeting()['agenda']);
    }

    /** Строка для datetime-local ('Y-m-d\TH:i') из значения БД, или '' если пусто. */
    private static function toInput(mixed $db): string
    {
        $db = (string) ($db ?? '');
        if ($db === '' || $db === '0000-00-00 00:00:00') { return ''; }
        $ts = strtotime($db);
        return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
    }

    /** «7 сентября 2026, 18:00–20:00» из starts_at/ends_at (БД-формат). */
    public static function formatDisplay(?string $startsAt, ?string $endsAt): string
    {
        if (!$startsAt) { return ''; }
        $s = strtotime($startsAt);
        if ($s === false) { return ''; }
        $out = (int) date('j', $s) . ' ' . self::MONTHS[(int) date('n', $s)] . ' ' . date('Y', $s) . ', ' . date('H:i', $s);

        if ($endsAt) {
            $e = strtotime($endsAt);
            if ($e !== false) {
                if (date('Y-m-d', $e) === date('Y-m-d', $s)) {
                    $out .= '–' . date('H:i', $e);   // тот же день → только время окончания
                } else {
                    $out .= ' – ' . (int) date('j', $e) . ' ' . self::MONTHS[(int) date('n', $e)] . ' '
                          . date('Y', $e) . ', ' . date('H:i', $e);
                }
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function splitAgenda(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));
    }
}
