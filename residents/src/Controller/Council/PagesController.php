<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, CouncilData, View};

/** Статические страницы совета: главная (документы/собрание/состав) и направления. */
final class PagesController
{
    private const LAYOUT = 'council/layout';

    public function home(): void
    {
        CouncilAuth::requireLogin();
        View::render('council/home', [
            'documents'   => CouncilData::documents(),
            'accounting'  => CouncilData::accounting(),
            'protocols'   => CouncilData::protocols(),
            'nextMeeting' => CouncilData::nextMeeting(),
            'members'     => CouncilData::members(),
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
