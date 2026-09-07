<?php
declare(strict_types=1);
namespace SkazResidents\Repository;

use SkazResidents\{Database, CouncilData};
use PDO;

/**
 * Ближайшее собрание совета — одна строка (id=1) в council_meeting.
 * Раньше данные жили в коде (CouncilData::nextMeeting()); теперь правятся
 * через форму. Если строки почему-то нет — отдаём дефолт из CouncilData,
 * чтобы главная не падала до наката схемы.
 *
 * agenda хранится текстом (по пункту на строку), наружу отдаётся массивом —
 * шаблон home.php перебирает $nextMeeting['agenda'] как список.
 */
final class CouncilMeetingRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** @return array{date:string,place:string,dutyChair:string,dutySecretary:string,agenda:array<int,string>} */
    public function get(): array
    {
        $row = $this->db->query('SELECT * FROM council_meeting WHERE id = 1')->fetch();
        if (!$row) {
            return CouncilData::nextMeeting();
        }
        return [
            'date'          => (string) $row['meeting_date'],
            'place'         => (string) $row['place'],
            'dutyChair'     => (string) $row['duty_chair'],
            'dutySecretary' => (string) $row['duty_secretary'],
            'agenda'        => self::splitAgenda((string) ($row['agenda'] ?? '')),
        ];
    }

    public function update(string $date, string $place, string $dutyChair, string $dutySecretary, string $agenda): void
    {
        // agenda нормализуем: CRLF→LF, обрезаем пустые строки по краям.
        $agenda = trim(str_replace("\r\n", "\n", $agenda));
        $st = $this->db->prepare(
            'INSERT INTO council_meeting (id, meeting_date, place, duty_chair, duty_secretary, agenda)
             VALUES (1, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                meeting_date = VALUES(meeting_date), place = VALUES(place),
                duty_chair = VALUES(duty_chair), duty_secretary = VALUES(duty_secretary),
                agenda = VALUES(agenda)'
        );
        $st->execute([$date, $place, $dutyChair, $dutySecretary, $agenda]);
    }

    /** Повестка как единый текст (для textarea в форме). */
    public function agendaText(): string
    {
        $row = $this->db->query('SELECT agenda FROM council_meeting WHERE id = 1')->fetch();
        return $row ? (string) ($row['agenda'] ?? '') : implode("\n", CouncilData::nextMeeting()['agenda']);
    }

    /** @return array<int,string> */
    private static function splitAgenda(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));
    }
}
