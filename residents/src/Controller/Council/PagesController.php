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
        $myId = (int) CouncilAuth::id();
        $me   = $this->members->findById($myId);
        $iAmDuty = !empty($me['is_duty_chair']);

        // Править встречу и передавать дежурство может текущий дежурный или админ.
        $canEditMeeting = CouncilAuth::isAdmin() || $iAmDuty;
        $dutyChair = $this->members->findDutyChair();

        // Кандидаты для передачи дежурства — активные члены совета, кроме текущего дежурного.
        $dutyCandidates = [];
        if ($canEditMeeting) {
            foreach ($this->members->all() as $m) {
                if ($m['status'] !== 'active' || ($m['role'] ?? '') === 'admin') { continue; }
                if (!empty($m['is_duty_chair'])) { continue; }
                $dutyCandidates[] = ['id' => (int) $m['id'], 'name' => (string) $m['name']];
            }
        }

        View::render('council/home', [
            'documents'      => CouncilData::documents(),
            'protocols'      => CouncilData::protocols(),
            'nextMeeting'    => $this->meeting->get(),
            'members'        => CouncilData::members(),
            'activeCount'    => count($this->tasks->listWithSubtasks(false, 'priority')),
            'directionsCount'=> count($directions),
            'canEditMeeting' => $canEditMeeting,
            'me'             => $me,
            'dutyChair'      => $dutyChair,
            'dutyCandidates' => $dutyCandidates,
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
