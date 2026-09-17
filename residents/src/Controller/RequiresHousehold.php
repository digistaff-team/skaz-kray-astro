<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Flash, Sections};
use SkazResidents\Repository\HouseholdProfileRepository;

/**
 * Гард разделов, завязанных на поместье (Книги, Инструменты, Ярмарка, Поездки,
 * Закупки): владелец записи = семья, поэтому раздел доступен только вошедшему
 * жителю с привязанным поместьем. Без поместья — редирект на выбор поместья
 * (как первый вход). Сам требует вход (Auth::requireLogin), поэтому заменяет его
 * в действиях.
 *
 * Принимает ключ раздела из Sections::LIST и первым делом проверяет, что раздел
 * не выключен админом (RequiresSection) — иначе прямая ссылка работала бы в
 * обход настройки.
 */
trait RequiresHousehold
{
    use RequiresSection;

    private function requireHousehold(string $sectionKey): void
    {
        $this->requireSection($sectionKey);
        Auth::requireLogin();
        if (!(new HouseholdProfileRepository())->householdByFamily(Auth::id())) {
            $label = Sections::LIST[$sectionKey] ?? $sectionKey;
            Flash::set('info', "Сначала выберите ваше поместье — после этого откроется раздел «{$label}».");
            header('Location: /poselenie/moye-pomestie/vybor');
            exit;
        }
    }
}
