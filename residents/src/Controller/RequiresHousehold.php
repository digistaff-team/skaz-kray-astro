<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Flash};
use SkazResidents\Repository\HouseholdProfileRepository;

/**
 * Гард разделов, завязанных на поместье (Книги, Инструменты, Ярмарка, Поездки):
 * владелец записи = семья, поэтому раздел доступен только вошедшему жителю с
 * привязанным поместьем. Без поместья — редирект на выбор поместья (как первый
 * вход). Сам требует вход (Auth::requireLogin), поэтому заменяет его в действиях.
 */
trait RequiresHousehold
{
    private function requireHousehold(string $section): void
    {
        Auth::requireLogin();
        if (!(new HouseholdProfileRepository())->householdByFamily(Auth::id())) {
            Flash::set('info', "Сначала выберите ваше поместье — после этого откроется раздел «{$section}».");
            header('Location: /poselenie/moye-pomestie/vybor');
            exit;
        }
    }
}
