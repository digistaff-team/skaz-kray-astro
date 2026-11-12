<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilAgendaRepository;

/**
 * Постоянные пункты повестки Совета: всегда первые в списке, их нельзя
 * удалить/переместить, при переносе встречи они не исчезают и сбрасываются.
 */
final class CouncilAgendaRepositoryTest extends TestCase
{
    private CouncilAgendaRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilAgendaRepository();
    }

    public function test_ensure_fixed_creates_two_items_at_top(): void
    {
        $this->repo->add('Вопрос по бюджету', 'Иван И.');
        $this->repo->ensureFixed();

        $items = $this->repo->all();
        $this->assertCount(3, $items);
        $this->assertSame('Изменения в составе Совета', $items[0]['title']);
        $this->assertSame('Статус по текущим задачам', $items[1]['title']);
        $this->assertSame(1, (int) $items[0]['is_fixed']);
        $this->assertSame(1, (int) $items[1]['is_fixed']);
        $this->assertSame('Вопрос по бюджету', $items[2]['title']);
    }

    public function test_ensure_fixed_is_idempotent(): void
    {
        $this->repo->ensureFixed();
        $this->repo->ensureFixed();
        $this->assertCount(2, $this->repo->all());
    }

    public function test_fixed_items_cannot_be_deleted_or_moved(): void
    {
        $this->repo->ensureFixed();
        [$first] = $this->repo->all();

        $this->assertFalse($this->repo->delete((int) $first['id']));
        $this->assertCount(2, $this->repo->all());

        // Перемещение вниз не меняет порядок.
        $this->repo->move((int) $first['id'], 1);
        $titles = array_column($this->repo->all(), 'title');
        $this->assertSame(['Изменения в составе Совета', 'Статус по текущим задачам'], $titles);
    }

    public function test_add_keeps_fixed_items_first_and_move_is_confined_to_custom(): void
    {
        $this->repo->ensureFixed();
        $a = $this->repo->add('Пункт А', 'Иван И.');
        $b = $this->repo->add('Пункт Б', 'Мария П.');

        // Обычный пункт можно поднять максимум до третьего места.
        $this->repo->move($b, -1);
        $titles = array_column($this->repo->all(), 'title');
        $this->assertSame(
            ['Изменения в составе Совета', 'Статус по текущим задачам', 'Пункт Б', 'Пункт А'],
            $titles
        );

        $this->repo->delete($a);
        $this->assertCount(3, $this->repo->all());
    }

    public function test_rollover_keeps_fixed_and_resets_them(): void
    {
        $this->repo->ensureFixed();
        $custom = $this->repo->add('ОБСУЖДЕНО обсуждённый', 'Иван И.');   // будет удалён
        $carry  = $this->repo->add('перенесётся', 'Мария П.');

        // Постоянный пункт отметили «обсуждено» — при переносе он сбрасывается.
        [$first] = $this->repo->all();
        $this->repo->toggleDiscussed((int) $first['id']);
        $this->repo->toggleDiscussed($custom);
        $this->repo->rollOver();

        $items = $this->repo->all();
        $titles = array_column($items, 'title');
        $this->assertSame(
            ['Изменения в составе Совета', 'Статус по текущим задачам', 'перенесётся'],
            $titles
        );
        $this->assertSame(0, (int) $items[0]['discussed']);   // сброшен
        $this->assertSame(0, (int) $items[0]['carried_over']); // без пометки «с прошлой встречи»
        $this->assertSame(1, (int) $items[2]['carried_over']); // обычный — с пометкой
        $this->assertNotNull($this->repo->findById($carry));
        $this->assertNull($this->repo->findById($custom));
    }
}