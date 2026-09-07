<?php
declare(strict_types=1);
namespace SkazResidents;

use SkazResidents\Repository\{CouncilMemberRepository, CouncilMeetingRepository};

/**
 * Автоматическая ротация Дежурного председателя по фиксированному графику.
 * Встречи еженедельные (понедельник), поэтому дежурный вычисляется по дате
 * встречи: неделя от якоря (14.09.2026 = позиция 0), по кругу ORDER.
 *
 * apply() синхронизирует под текущую встречу (council_meeting.starts_at):
 * ставит is_duty_chair нужному члену и обновляет подпись в карточке. Вызывается
 * при сохранении встречи и ежедневно из cron рассылки.
 */
final class CouncilDutyRotation
{
    /** Якорная дата графика (первая встреча ротации). */
    private const ANCHOR = '2026-09-14';

    /** Порядок ротации — ИМЕНА как в council_members (Имя Фамилия). */
    private const ORDER = [
        'Ольга Жулидова',        // 14.09.2026
        'Юрий Моисеенко',        // 21.09
        'Александр Людоговский', // 28.09
        'Сергей Шубин',          // 05.10
        'Анастасия Малиновская', // 12.10
        'Максим Жулидов',        // 19.10
        'Александр Бобков',      // 26.10
        'Марина Людоговская',    // 02.11
        'Катерина Шульженко',    // 09.11
        'Елена Моисеенко',       // 16.11
        'Наталья Нецветова',     // 23.11
        // далее цикл повторяется
    ];

    /** Имя дежурного на дату встречи (Y-m-d), либо null (до начала графика/ошибка). */
    public static function chairNameForDate(string $ymd): ?string
    {
        $tz = new \DateTimeZone('Europe/Moscow');
        $anchor = new \DateTimeImmutable(self::ANCHOR, $tz);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), $tz);
        if ($d === false) { return null; }

        $days = (int) $anchor->diff($d)->format('%r%a');   // знаковое число дней
        if ($days < 0) { return null; }                    // раньше начала графика
        $week = (int) round($days / 7);
        return self::ORDER[$week % count(self::ORDER)];
    }

    /**
     * Синхронизировать дежурного под текущую встречу: is_duty_chair + подпись в
     * карточке. Возвращает имя назначенного дежурного или null (нет даты/до графика).
     */
    public static function apply(): ?string
    {
        $meetingRepo = new CouncilMeetingRepository();
        $meeting = $meetingRepo->get();
        $startsAt = (string) ($meeting['startsAt'] ?? '');   // 'Y-m-d\TH:i' или ''
        if ($startsAt === '') { return null; }

        $name = self::chairNameForDate($startsAt);
        if ($name === null) { return null; }

        $members = new CouncilMemberRepository();
        $member = $members->findByName($name);
        if (!$member) { return null; }

        $members->setDutyChair((int) $member['id']);   // снять у всех, поставить одному
        $meetingRepo->setDutyChairName($name);          // подпись в карточке встречи
        return $name;
    }
}
