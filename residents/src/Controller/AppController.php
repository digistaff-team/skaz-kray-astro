<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Service\AppDashboard;
use SkazResidents\Repository\HouseholdProfileRepository;

/**
 * Мобильный лаунчер /poselenie/app и офлайн-страница /poselenie/offline.
 * Гард — Auth::requireLogin() (нужны имя жителя и статус дневника). Плитка «Совет»
 * ведёт на /sovet (раздельный вход совета — там своя сессия).
 */
final class AppController
{
    public function __construct(
        private AppDashboard $dashboard = new AppDashboard(),
        private HouseholdProfileRepository $households = new HouseholdProfileRepository()
    ) {}

    public function home(): void
    {
        Auth::requireLogin();
        // Под «Сказочный Край» показываем название привязанного поместья; пока
        // поместье не привязано — имя жителя (как было).
        $household = $this->households->householdByFamily(Auth::id());
        $estate = $household ? trim((string) $household['estate_name']) : '';
        $me = $estate !== '' ? ('Поместье «' . $estate . '»') : Auth::name();
        View::render('app/home', [
            'dash'    => $this->dashboard->build(Auth::id(), date('Y-m-d')),
            'me'      => $me,
            'savedAt' => date('H:i'),
        ], 'Приложение', 'app/layout');
    }

    public function offline(): void
    {
        Auth::requireLogin();
        View::render('app/offline', [], 'Нет сети');
    }
}
