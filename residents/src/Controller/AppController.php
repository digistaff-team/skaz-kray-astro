<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
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
        View::render('app/home', [
            'dash'    => $this->dashboard->build(Auth::id(), date('Y-m-d')),
            'me'      => Auth::name(),
            'savedAt' => date('H:i'),
        ], 'Приложение');
    }

    public function offline(): void
    {
        Auth::requireLogin();
        View::render('app/offline', [], 'Нет сети');
    }
}
