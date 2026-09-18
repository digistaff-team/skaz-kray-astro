<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, View};

/**
 * «Общий дом» — раздел-хаб для жителей: бронирование помещений и отчёт о
 * расходах.
 *
 * Своих данных у раздела нет. Бронирование живёт в отдельном мини-приложении
 * @SkazTerem_bot (ссылка настраивается ключом terem_booking_link), а отчёт —
 * это существующая страница «Бюджет Общего дома» (/poselenie/byudzhet), где
 * жители видят те же цифры, что совет ведёт в «Бухгалтерии Общего дома».
 */
final class CommonHouseController
{
    use RequiresSection;

    private const BOOKING_LINK = 'https://t.me/SkazTerem_bot/booking';

    public function index(): void
    {
        $this->requireSection('obshchiy-dom');
        Auth::requireLogin();
        View::render('common/house', [
            'bookingLink' => (string) (Config::get('terem_booking_link', self::BOOKING_LINK) ?: self::BOOKING_LINK),
            'budgetOpen'  => \SkazResidents\Sections::isEnabled('byudzhet'),
        ], 'Общий дом');
    }
}
