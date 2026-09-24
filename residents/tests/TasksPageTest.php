<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/** Страница «Мои дела»: список дел и пустое состояние. */
final class TasksPageTest extends TestCase
{
    /** @param array<int,array<string,mixed>> $tasks */
    private function render(array $tasks): string
    {
        ob_start();
        require __DIR__ . '/../src/templates/tasks/index.php';
        return (string) ob_get_clean();
    }

    public function test_lists_tasks_with_links_and_marks_urgent(): void
    {
        $html = $this->render([
            ['kind' => 'tool_overdue', 'title' => 'Пора вернуть «Пила»', 'detail' => 'Семья Руденко · срок был 24 сентября 2026',
             'link' => '/poselenie/instrumenty/moi', 'date' => '2026-09-24', 'urgent' => true],
            ['kind' => 'tool_request', 'title' => 'Заявка на «Дрель»', 'detail' => 'Семья Руденко',
             'link' => '/poselenie/instrumenty/moi', 'date' => '2026-09-20 10:00:00', 'urgent' => false],
        ]);
        $this->assertStringContainsString('<h1>Мои дела</h1>', $html);
        $this->assertSame(2, substr_count($html, 'class="task-item'));
        $this->assertSame(1, substr_count($html, 'task-item--urgent'));
        $this->assertStringContainsString('Пора вернуть «Пила»', $html);
        $this->assertStringContainsString('href="/poselenie/instrumenty/moi"', $html);
    }

    public function test_empty_state_says_everything_is_done(): void
    {
        $html = $this->render([]);
        $this->assertStringContainsString('Все дела сделаны', $html);
        $this->assertStringNotContainsString('task-item', $html);
    }

    public function test_titles_are_escaped(): void
    {
        $html = $this->render([['kind' => 'tool_request', 'title' => 'Заявка на «<b>x</b>»', 'detail' => '',
            'link' => '/poselenie/instrumenty/moi', 'date' => '', 'urgent' => false]]);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }
}
