<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, CouncilData, View};
use SkazResidents\Repository\CouncilTaskRepository;

/** Статические страницы совета: главная (документы/собрание/состав) и направления. */
final class PagesController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilTaskRepository $tasks = new CouncilTaskRepository()
    ) {}

    public function home(): void
    {
        CouncilAuth::requireLogin();
        $directions = CouncilData::directions();
        View::render('council/home', [
            'documents'      => CouncilData::documents(),
            'accounting'     => CouncilData::accounting(),
            'protocols'      => CouncilData::protocols(),
            'nextMeeting'    => CouncilData::nextMeeting(),
            'members'        => CouncilData::members(),
            'activeCount'    => count($this->tasks->listWithSubtasks(false, 'priority')),
            'directionsCount'=> count($directions),
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
