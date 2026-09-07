<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, CouncilData, View};
use SkazResidents\Repository\{CouncilTaskRepository, CouncilMeetingRepository, CouncilMemberRepository};

/** Статические страницы совета: главная (документы/собрание/состав) и направления. */
final class PagesController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository(),
        private CouncilMeetingRepository $meeting = new CouncilMeetingRepository(),
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    public function home(): void
    {
        CouncilAuth::requireLogin();
        $directions = CouncilData::directions();
        // Править встречу может текущий Дежурный председатель или админ.
        $canEditMeeting = CouncilAuth::isAdmin()
            || $this->members->isDutyChair((int) CouncilAuth::id());
        View::render('council/home', [
            'documents'      => CouncilData::documents(),
            'protocols'      => CouncilData::protocols(),
            'nextMeeting'    => $this->meeting->get(),
            'members'        => CouncilData::members(),
            'activeCount'    => count($this->tasks->listWithSubtasks(false, 'priority')),
            'directionsCount'=> count($directions),
            'canEditMeeting' => $canEditMeeting,
        ], 'Попечительский совет', self::LAYOUT);
    }

    public function directions(): void
    {
        CouncilAuth::requireLogin();
        View::render('council/directions', [
            'directions' => CouncilData::directions(),
        ], 'Направления работы', self::LAYOUT);
    }
}
