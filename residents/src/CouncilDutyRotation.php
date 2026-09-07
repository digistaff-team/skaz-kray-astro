<?php
declare(strict_types=1);
namespace SkazResidents;

use SkazResidents\Repository\{CouncilMemberRepository, CouncilMeetingRepository};

/**
 * Ротация Дежурного председателя по фиксированной очерёдности (ORDER).
 *
 * Ротация привязана к ПОСЛЕДОВАТЕЛЬНОСТИ проведённых встреч, а не к календарю:
 * позиция хранится в council_meeting.rotation_index и двигается на +1 только
 * когда встреча состоялась (advance() из cron понедельника 23:59). Поэтому
 * перенос даты встречи НЕ сдвигает очередь — тот же дежурный остаётся на
 * перенесённую встречу, а весь график естественно сдвигается на неделю.
 *
 * apply() — синхронизирует дежурного под текущий индекс (флаг is_duty_chair +
 * подпись в карточке). Вызывается при сохранении встречи и в cron рассылки.
 */
final class CouncilDutyRotation
{
    /** Очерёдность — ИМЕНА как в council_members (Имя Фамилия). */
    private const ORDER = [
        'Ольга Жулидова',        // поз. 0
        'Юрий Моисеенко',        // 1
        'Александр Людоговский', // 2
        'Сергей Шубин',          // 3
        'Анастасия Малиновская', // 4
        'Максим Жулидов',        // 5
        'Александр Бобков',      // 6
        'Марина Людоговская',    // 7
        'Катерина Шульженко',    // 8
        'Елена Моисеенко',       // 9
        'Наталья Нецветова',     // 10
    ];

    public static function nameForIndex(int $i): string
    {
        $n = count(self::ORDER);
        return self::ORDER[(($i % $n) + $n) % $n];
    }

    /** Синхронизировать дежурного под сохранённый индекс. Возвращает имя или null. */
    public static function apply(): ?string
    {
        $meetingRepo = new CouncilMeetingRepository();
        $name = self::nameForIndex($meetingRepo->rotationIndex());

        $members = new CouncilMemberRepository();
        $member = $members->findByName($name);
        if (!$member) { return null; }

        $members->setDutyChair((int) $member['id']);
        $meetingRepo->setDutyChairName($name);
        return $name;
    }

    /**
     * Провести ротацию после состоявшейся встречи: дата +7 дней (следующий
     * понедельник, тот же час), индекс +1, новый дежурный, свежая повестка,
     * секретарь сброшен. @return array{date:string,chair:string}
     */
    public static function advance(): array
    {
        $meetingRepo = new CouncilMeetingRepository();
        $m = $meetingRepo->get();
        $startsAt = (string) ($m['startsAt'] ?? '');
        if ($startsAt === '') { return ['date' => '', 'chair' => '']; }

        $dt = new \DateTime($startsAt, new \DateTimeZone('Europe/Moscow'));
        $dt->modify('+7 days');
        $newStartsDb = $dt->format('Y-m-d H:i:s');

        $newIdx = ($meetingRepo->rotationIndex() + 1) % count(self::ORDER);
        $chair = self::nameForIndex($newIdx);

        // Новая встреча: та же площадка, новый дежурный, повестка сброшена.
        // Дежурный секретарь НЕ ротируется — переносим как есть.
        $meetingRepo->update($newStartsDb, null, (string) ($m['place'] ?? ''), $chair, (string) ($m['dutySecretary'] ?? ''), 'В процессе формирования');
        $meetingRepo->setRotationIndex($newIdx);

        $members = new CouncilMemberRepository();
        if ($member = $members->findByName($chair)) {
            $members->setDutyChair((int) $member['id']);
        }
        return ['date' => $dt->format('Y-m-d H:i'), 'chair' => $chair];
    }
}
