<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\Auth;
use SkazResidents\Service\WaterLevel;

/**
 * Панель «Уровень воды в Шебше» — содержимое модального окна на главной
 * приложения (иконка-капля в хедере, рядом с картой). Отдаёт только фрагмент
 * HTML: оверлей подгружает его при первом открытии, чтобы замер не спрашивали
 * на каждой странице портала (так же лениво, как картинка карты).
 */
final class WaterLevelController
{
    public function __construct(
        private WaterLevel $water = new WaterLevel()
    ) {}

    public function panel(): void
    {
        Auth::requireLogin();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');   // замер живёт час, кешировать фрагмент нечего
        $water = $this->water->panel(date('Y-m-d H:i:s'));
        require __DIR__ . '/../templates/partials/water_panel.php';
    }
}
