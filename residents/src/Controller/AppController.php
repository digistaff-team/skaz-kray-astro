<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, CouncilAuth, Config, View};
use SkazResidents\Service\AppDashboard;

/**
 * Мобильный лаунчер /poselenie/app и офлайн-страница /poselenie/offline.
 * Гард — Auth::requireLogin() (нужны имя жителя и статус дневника). Плитки/действия
 * совета показываются только при активной council-сессии (CouncilAuth::id()).
 */
final class AppController
{
    public function __construct(
        private AppDashboard $dashboard = new AppDashboard()
    ) {}

    public function home(): void
    {
        Auth::requireLogin();
        $hasCouncil = CouncilAuth::id() !== null;
        $data = $this->dashboard->build(
            Auth::id(),
            $hasCouncil ? CouncilAuth::name() : null,
            date('Y-m-d')
        );
        View::render('app/home', [
            'dash'       => $data,
            'me'         => Auth::name(),
            'hasCouncil' => $hasCouncil,
            'savedAt'    => date('H:i'),
        ], 'Приложение');
    }

    public function offline(): void
    {
        Auth::requireLogin();
        View::render('app/offline', [], 'Нет сети');
    }
}
