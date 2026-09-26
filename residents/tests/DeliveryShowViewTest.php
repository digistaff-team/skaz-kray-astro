<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{DeliveryRepository, TripRepository, FamilyRepository, SectionSettingsRepository};
use SkazResidents\Service\DeliveryPolicy as P;
use SkazResidents\Sections;

/**
 * Рендер карточки заявки на доставку (delivery/show.php), вкладок «Поездки |
 * Доставка» и страницы «Действие недоступно». Строки заявки — настоящие, из
 * DeliveryRepository::findDetailed, данные шаблона — как их собирает
 * DeliveryController::show().
 */
final class DeliveryShowViewTest extends TestCase
{
    private const NOW = '2026-09-26 10:00:00';
    private DeliveryRepository $repo;
    private int $req;
    private int $drv;
    private int $car;
    private int $oth;

    protected function setUp(): void
    {
        $_SESSION = [];
        $pdo = make_test_db();
        $fam = new FamilyRepository();
        $this->req = $fam->createPending('req@skaz-kray.ru', 'H', 'Семья Орловых');
        $this->drv = $fam->createPending('drv@skaz-kray.ru', 'H', 'Семья Соколовых');
        $this->car = $fam->createPending('car@skaz-kray.ru', 'H', 'Семья Лебедевых');
        $this->oth = $fam->createPending('oth@skaz-kray.ru', 'H', 'Семья Ивановых');
        $tg = $pdo->prepare('UPDATE families SET telegram_username = ? WHERE id = ?');
        $tg->execute(['orlova_tg', $this->req]);
        $tg->execute(['lebedev_tg', $this->car]);
        $this->repo = new DeliveryRepository();
    }

    /** Данные шаблона — как в DeliveryController::show(). */
    private function show(int $id, int $me): string
    {
        $d = $this->repo->findDetailed($id);
        $this->assertNotNull($d);
        $driver = $d['trip_driver_id'] !== null ? (int) $d['trip_driver_id'] : null;
        return $this->render('delivery/show', [
            'd'           => $d,
            'actions'     => P::actions($d, $me, $driver, 0, P::tripLive($d, date('Y-m-d'))),
            'private'     => P::seesPrivate($d, $me),
            'isRequester' => (int) $d['requester_id'] === $me,
            'receipts'    => [],
        ]);
    }

    /** @param array<string,mixed> $vars */
    private function render(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/../src/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    private function board(string $kind = 'pickup', ?string $code = 'SECRET-4417'): int
    {
        return $this->repo->create($this->req, null, $kind, 'Посылка', 'СДЭК', null, null, $code, null, self::NOW);
    }

    private function accepted(): int
    {
        $id = $this->board();
        $this->assertTrue($this->repo->take($id, $this->car, 'open', self::NOW));
        return $id;
    }

    public function test_requester_sees_carrier_contact_with_telegram_link(): void
    {
        $html = $this->show($this->accepted(), $this->req);
        $this->assertStringContainsString(
            'Контакт исполнителя: Семья Лебедевых (Telegram: <a class="js-tg-link" href="https://t.me/lebedev_tg"', $html);
        $this->assertStringNotContainsString('Контакт заказчика', $html);
    }

    public function test_carrier_sees_requester_contact(): void
    {
        $html = $this->show($this->accepted(), $this->car);
        $this->assertStringContainsString(
            'Контакт заказчика: Семья Орловых (Telegram: <a class="js-tg-link" href="https://t.me/orlova_tg"', $html);
        $this->assertStringNotContainsString('Контакт исполнителя', $html);
    }

    public function test_outsider_on_open_request_sees_no_contacts_and_no_code(): void
    {
        $html = $this->show($this->board(), $this->oth);
        $this->assertStringNotContainsString('Контакт исполнителя', $html);
        $this->assertStringNotContainsString('Контакт заказчика', $html);
        $this->assertStringNotContainsString('js-tg-link', $html);
        $this->assertStringNotContainsString('SECRET-4417', $html);
        $this->assertStringContainsString('/vzyat', $html, 'а «Возьму» — есть');
    }

    public function test_requester_without_carrier_sees_no_contact(): void
    {
        $html = $this->show($this->board(), $this->req);
        $this->assertStringNotContainsString('Контакт исполнителя', $html);
        $this->assertStringNotContainsString('Контакт заказчика', $html);
        $this->assertStringContainsString('SECRET-4417', $html, 'свой код заказчик видит');
    }

    public function test_service_address_is_not_shown_as_contact(): void
    {
        \SkazResidents\Database::pdo()->prepare('UPDATE families SET telegram_username = NULL, email = ? WHERE id = ?')
            ->execute(['12345@telegram.local', $this->car]);
        $html = $this->show($this->accepted(), $this->req);
        $this->assertStringContainsString('Контакт исполнителя: Семья Лебедевых</p>', $html);
        $this->assertStringNotContainsString('почта:', $html);
        $this->assertStringNotContainsString('telegram.local', $html);
    }

    public function test_requester_of_request_to_past_trip_can_move_it_to_board(): void
    {
        $past = (new TripRepository())->create($this->drv, 'Край', 'Северская',
            date('Y-m-d', strtotime('-1 day')), '10:00', 3, null, self::NOW);
        $id = $this->repo->create($this->req, $past, 'buy', 'Хлеб', 'Магнит', null, null, null, null, self::NOW);

        $html = $this->show($id, $this->req);
        $this->assertStringContainsString('/poselenie/dostavka/' . $id . '/na-dosku', $html);
        $this->assertStringContainsString('Выложить на доску', $html);
        $this->assertStringContainsString('/poselenie/dostavka/' . $id . '/otmenit', $html);

        $driverHtml = $this->show($id, $this->drv);
        $this->assertStringNotContainsString('/vzyat', $driverHtml, 'водитель прошедшей поездки не берёт');
        $this->assertStringNotContainsString('/ne-smogu', $driverHtml);
    }

    public function test_request_to_live_trip_waits_for_driver(): void
    {
        $future = (new TripRepository())->create($this->drv, 'Край', 'Северская',
            date('Y-m-d', strtotime('+2 day')), '10:00', 3, null, self::NOW);
        $id = $this->repo->create($this->req, $future, 'buy', 'Хлеб', 'Магнит', null, null, null, null, self::NOW);
        $this->assertStringNotContainsString('/na-dosku', $this->show($id, $this->req));
        $this->assertStringContainsString('/vzyat', $this->show($id, $this->drv));
    }

    public function test_trip_tabs_only_when_both_sections_enabled(): void
    {
        $tabs = fn(): string => $this->render('partials/trip-tabs', ['tab' => 'dostavka']);
        $sections = new SectionSettingsRepository();

        Sections::reset();
        $html = $tabs();
        $this->assertStringContainsString('href="/poselenie/poezdki"', $html);
        $this->assertStringContainsString('href="/poselenie/dostavka"', $html);

        $sections->setEnabled('poezdki', false, self::NOW);
        Sections::reset();
        $this->assertSame('', trim($tabs()), 'поездки выключены');

        $sections->setEnabled('poezdki', true, self::NOW);
        $sections->setEnabled('dostavka', false, self::NOW);
        Sections::reset();
        $this->assertSame('', trim($tabs()), 'доставка выключена');
    }

    public function test_forbidden_page(): void
    {
        $html = $this->render('public/forbidden', []);
        $this->assertStringContainsString('Действие недоступно', $html);
        $this->assertStringContainsString('href="/poselenie/dostavka/moi"', $html);
        $this->assertStringContainsString('href="/poselenie/dostavka"', $html);
    }
}
