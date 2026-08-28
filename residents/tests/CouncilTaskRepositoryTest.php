<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Repository\CouncilTaskRepository;

final class CouncilTaskRepositoryTest extends TestCase
{
    private CouncilTaskRepository $repo;

    protected function setUp(): void
    {
        make_test_db();
        $this->repo = new CouncilTaskRepository();
    }

    public function test_create_defaults(): void
    {
        $id = $this->repo->create('Починить калитку', null, 'Елена К.', '', 'средняя');
        $t = $this->repo->find($id);
        $this->assertSame('Починить калитку', $t['title']);
        $this->assertSame('новая', $t['status']);
        $this->assertSame(0, (int) $t['progress']);
    }

    public function test_completed_at_set_on_first_done_and_cleared_on_reopen(): void
    {
        $id = $this->repo->create('Задача', null, 'А', '', 'средняя');

        $this->repo->updateFields($id, ['status' => 'выполнена', 'progress' => 100], '2026-08-28 12:00:00');
        $this->assertSame('2026-08-28 12:00:00', $this->repo->find($id)['completed_at']);

        // Повторное обновление другого поля не сдвигает completed_at.
        $this->repo->updateFields($id, ['status' => 'выполнена', 'assignee' => 'Б'], '2026-08-28 13:00:00');
        $this->assertSame('2026-08-28 12:00:00', $this->repo->find($id)['completed_at']);

        // Возврат в работу — completed_at сбрасывается.
        $this->repo->updateFields($id, ['status' => 'в работе'], '2026-08-28 14:00:00');
        $this->assertNull($this->repo->find($id)['completed_at']);
    }

    public function test_progress_clamped(): void
    {
        $id = $this->repo->create('Задача', null, 'А', '', 'средняя');
        $this->repo->updateFields($id, ['progress' => 500], '2026-08-28 12:00:00');
        $this->assertSame(100, (int) $this->repo->find($id)['progress']);
        $this->repo->updateFields($id, ['progress' => -5], '2026-08-28 12:00:00');
        $this->assertSame(0, (int) $this->repo->find($id)['progress']);
    }

    public function test_active_vs_archive_split_and_priority_sort(): void
    {
        $this->repo->create('низкий', null, 'А', '', 'низкая');
        $this->repo->create('высокий', null, 'А', '', 'высокая');
        $doneId = $this->repo->create('готовый', null, 'А', '', 'средняя');
        $this->repo->updateFields($doneId, ['status' => 'выполнена'], '2026-08-28 12:00:00');

        $active = $this->repo->listWithSubtasks(false, 'priority');
        $this->assertCount(2, $active);
        $this->assertSame('высокий', $active[0]['title']); // высокая раньше низкой

        $archive = $this->repo->listWithSubtasks(true, 'priority');
        $this->assertCount(1, $archive);
        $this->assertSame('готовый', $archive[0]['title']);
    }

    public function test_subtasks_lifecycle_and_counts(): void
    {
        $id = $this->repo->create('Задача', null, 'А', '', 'средняя');
        $s1 = $this->repo->addSubtask($id, 'Осмотреть петли');
        $s2 = $this->repo->addSubtask($id, 'Купить фурнитуру');

        $this->repo->toggleSubtask($s1, true);
        $this->repo->renameSubtask($s2, 'Купить и привезти фурнитуру');

        $task = $this->repo->listWithSubtasks(false, 'priority')[0];
        $this->assertSame(2, $task['total_count']);
        $this->assertSame(1, $task['done_count']);
        $this->assertSame('Купить и привезти фурнитуру', $task['subtasks'][1]['title']);

        $this->repo->deleteSubtask($s1);
        $task = $this->repo->listWithSubtasks(false, 'priority')[0];
        $this->assertSame(1, $task['total_count']);
    }

    public function test_delete_removes_subtasks(): void
    {
        $id = $this->repo->create('Задача', null, 'А', '', 'средняя');
        $this->repo->addSubtask($id, 'п1');
        $this->repo->delete($id);
        $this->assertNull($this->repo->find($id));
        $this->assertSame([], $this->repo->listWithSubtasks(false, 'priority'));
    }
}
