<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\{PurchaseRepository, PurchaseOrderRepository, FamilyRepository};

/**
 * Совместные закупки: пул считается по заявкам, поэтому проверяем именно
 * арифметику объёма/сумм и правило «одна заявка на семью».
 */
final class PurchaseRepositoryTest extends TestCase
{
    private PurchaseRepository $purchases;
    private PurchaseOrderRepository $orders;
    private int $organizer;
    private int $neighbour;
    private const NOW = '2026-09-18 10:00:00';

    protected function setUp(): void
    {
        make_test_db();
        $families = new FamilyRepository();
        $this->organizer = $families->createPending('org@skaz-kray.ru', 'H', 'Поместье Организатора');
        $this->neighbour = $families->createPending('nei@skaz-kray.ru', 'H', 'Поместье Соседа');
        $this->purchases = new PurchaseRepository();
        $this->orders = new PurchaseOrderRepository();
    }

    private function newPurchase(?string $target = '100', ?string $price = '450'): int
    {
        return $this->purchases->create(
            $this->organizer, 'Мёд гречишный', 'Продукты', 'кг', $price, $target,
            '2026-10-20', 'Пасека Ивановых', 'Общий дом', 'Фляги по 40 кг', self::NOW
        );
    }

    public function test_create_starts_collecting_and_empty(): void
    {
        $id = $this->newPurchase();
        $p = $this->purchases->findWithTotals($id);
        $this->assertSame('collecting', $p['status']);
        $this->assertTrue($p['is_open']);
        $this->assertSame(0.0, $p['collected_qty']);
        $this->assertSame(0, (int) $p['participants']);
        $this->assertSame(0, $p['percent']);
    }

    public function test_orders_sum_into_progress_and_money(): void
    {
        $id = $this->newPurchase();
        $this->orders->place($id, $this->organizer, '30', null, self::NOW);
        $this->orders->place($id, $this->neighbour, '48.5', 'в двух флягах', self::NOW);

        $p = $this->purchases->findWithTotals($id);
        $this->assertSame(78.5, $p['collected_qty']);
        $this->assertSame(2, (int) $p['participants']);
        $this->assertSame(79, $p['percent']);                 // 78.5 из 100 кг
        $this->assertSame(78.5 * 450, $p['total_sum']);
        $this->assertSame(0.0, $p['paid_sum']);
    }

    public function test_second_order_of_same_family_edits_the_first(): void
    {
        $id = $this->newPurchase();
        $first = $this->orders->place($id, $this->neighbour, '10', null, self::NOW);
        $again = $this->orders->place($id, $this->neighbour, '25', 'передумали, берём больше', self::NOW);

        $this->assertSame($first, $again, 'заявка та же строка, а не вторая');
        $this->assertCount(1, $this->orders->listFor($id));
        $this->assertSame(25.0, (float) $this->orders->findFor($id, $this->neighbour)['qty']);
        $this->assertSame(25.0, $this->purchases->findWithTotals($id)['collected_qty']);
    }

    public function test_paid_mark_counts_separately(): void
    {
        $id = $this->newPurchase();
        $this->orders->place($id, $this->neighbour, '20', null, self::NOW);
        $order = $this->orders->findFor($id, $this->neighbour);

        $this->orders->setPaid((int) $order['id'], true, self::NOW);
        $p = $this->purchases->findWithTotals($id);
        $this->assertSame(20.0, $p['paid_qty']);
        $this->assertSame(20.0 * 450, $p['paid_sum']);

        $this->orders->setPaid((int) $order['id'], false, self::NOW);
        $this->assertSame(0.0, $this->purchases->findWithTotals($id)['paid_qty']);
        $this->assertNull($this->orders->findFor($id, $this->neighbour)['paid_at']);
    }

    public function test_withdraw_removes_family_from_pool(): void
    {
        $id = $this->newPurchase();
        $this->orders->place($id, $this->neighbour, '12', null, self::NOW);
        $this->orders->remove((int) $this->orders->findFor($id, $this->neighbour)['id']);

        $this->assertNull($this->orders->findFor($id, $this->neighbour));
        $this->assertSame(0.0, $this->purchases->findWithTotals($id)['collected_qty']);
    }

    public function test_purchase_without_target_or_price_has_no_percent_and_no_sum(): void
    {
        $id = $this->newPurchase(null, null);
        $this->orders->place($id, $this->neighbour, '5', null, self::NOW);

        $p = $this->purchases->findWithTotals($id);
        $this->assertSame(5.0, $p['collected_qty']);
        $this->assertNull($p['percent']);
        $this->assertNull($p['total_sum']);
    }

    public function test_percent_never_exceeds_hundred(): void
    {
        $id = $this->newPurchase('10');
        $this->orders->place($id, $this->neighbour, '25', null, self::NOW);
        $this->assertSame(100, $this->purchases->findWithTotals($id)['percent']);
    }

    public function test_status_changes_and_open_flag(): void
    {
        $id = $this->newPurchase();
        $this->purchases->setStatus($id, 'ordered', self::NOW);
        $p = $this->purchases->findWithTotals($id);
        $this->assertSame('ordered', $p['status']);
        $this->assertFalse($p['is_open']);

        $this->purchases->setStatus($id, 'не-статус', self::NOW);
        $this->assertSame('ordered', $this->purchases->findById($id)['status'], 'мусорный статус игнорируется');
    }

    public function test_board_puts_collecting_first_and_filters(): void
    {
        $collecting = $this->newPurchase();
        $done = $this->newPurchase();
        $this->purchases->setStatus($done, 'done', self::NOW);

        $board = $this->purchases->listBoard();
        $this->assertSame($collecting, (int) $board[0]['id']);
        $this->assertCount(1, $this->purchases->listBoard('', '', 'done'));
        $this->assertCount(2, $this->purchases->listBoard('Мёд'));
        $this->assertCount(0, $this->purchases->listBoard('щебень'));
        $this->assertSame(1, $this->purchases->countCollecting());
    }

    public function test_my_lists(): void
    {
        $id = $this->newPurchase();
        $this->orders->place($id, $this->neighbour, '7', null, self::NOW);

        $this->assertCount(1, $this->purchases->listByOrganizer($this->organizer));
        $this->assertCount(0, $this->purchases->listByOrganizer($this->neighbour));

        $mine = $this->purchases->listByParticipant($this->neighbour);
        $this->assertCount(1, $mine);
        $this->assertSame(7.0, (float) $mine[0]['my_qty']);
    }

    public function test_categories_merge_popular_and_own(): void
    {
        $this->purchases->create($this->organizer, 'Щебень', 'Своя категория', 'м³', null, null, null, null, null, null, self::NOW);
        $cats = $this->purchases->categoriesForForm();
        $this->assertContains('Продукты', $cats);          // ходовая
        $this->assertContains('Своя категория', $cats);    // заведена вручную
        $this->assertSame(array_values(array_unique($cats)), $cats);
    }

    public function test_delete_removes_orders_too(): void
    {
        $id = $this->newPurchase();
        $this->orders->place($id, $this->neighbour, '3', null, self::NOW);
        $this->purchases->delete($id);

        $this->assertNull($this->purchases->findById($id));
        $this->assertSame([], $this->orders->listFor($id));
    }

    public function test_totals_count_orders_without_payment(): void
    {
        // «Мои дела» просят организатора отметить оплату, пока есть неоплаченные.
        $id = $this->newPurchase();
        $paid = $this->orders->place($id, $this->organizer, '2', null, self::NOW);
        $this->orders->place($id, $this->neighbour, '3', null, self::NOW);
        $this->orders->setPaid($paid, true, self::NOW);

        $p = $this->purchases->listByOrganizer($this->organizer)[0];
        $this->assertSame(1, (int) $p['unpaid_count']);
    }
}
