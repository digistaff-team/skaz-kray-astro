<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * AJAX-добавление подзадач в Совете: страница не перезагружается, задача
 * остаётся раскрытой, можно добавить несколько подзадач подряд.
 *
 * Ловит два родственных бага, оба давали выход из задачи + дубль подзадачи:
 *  - флаг «форма уже отправлялась» на переиспользуемой форме: после первой же
 *    удачной отправки следующая уходила бы обычным POST с редиректом;
 *  - запасной form.submit() в catch(): он обходит submit-перехваты (confirm,
 *    submit-guard) и уводит со страницы, а при возврате «назад» браузер
 *    повторяет POST — на сервере второй дубль.
 */
final class SubtasksAjaxTest extends TestCase
{
    private const TASKS = __DIR__ . '/../src/templates/council/tasks.php';

    private function js(): string
    {
        $src = (string) file_get_contents(self::TASKS);
        $this->assertStringContainsString('initSovetSubs', $src, 'в tasks.php нет скрипта подзадач');
        return $src;
    }

    /** Перехват должен идти раньше confirm-окна, иначе data-confirm перехватит отправку первым. */
    public function test_submit_is_captured_on_document_before_confirm_dialog(): void
    {
        $js = $this->js();
        $add = strpos($js, '.sovet-sub-add');
        $this->assertNotFalse($add, 'нет обработчика формы добавления');

        $capture = substr($js, $add, strpos($js, '</script>', $add) - $add);
        $this->assertMatchesRegularExpression(
            "/addEventListener\\(\\s*'submit'[\\s\\S]*?,\\s*true\\s*\\)/",
            $capture,
            'перехват добавления должен висеть на document в capture-фазе — как confirm.php'
        );
    }

    /** Повторное нажатие «+» идёт следующим fetch-запросом, а не обычным POST. */
    public function test_resubmit_is_blocked_without_falling_back_to_native_post(): void
    {
        $js = $this->js();
        // Строка с флагом: пока запрос идёт, следующая отправка гасится, но не уходит нативно.
        $this->assertMatchesRegularExpression(
            "/if\\s*\\(\\s*sent\\s*\\|\\|\\s*!window\\.fetch\\s*\\)\\s*\\{\\s*e\\.preventDefault\\(\\)/",
            $js,
            'повторная отправка должна гаситься preventDefault, а не уходить обычным POST'
        );
        // Ошибка не должна уводить со страницы: form.submit() обходит все перехваты.
        $this->assertStringNotContainsString('form.submit()', $js, 'запасной form.submit() уводит со страницы и даёт дубль');
        // А повторить попытку после ошибки можно — флаг снимается.
        $this->assertStringContainsString('sent = false', $js, 'после ошибки флаг должен сниматься, иначе кнопка «+» умрёт');
    }

    /** Ранний выход (без fetch) тоже не должен уходить обычным POST. */
    public function test_early_return_prevents_default_submission(): void
    {
        $js = $this->js();
        $pos = strpos($js, 'if (sent || !window.fetch)');
        $this->assertNotFalse($pos, 'нет раннего выхода по флагу/fetch');
        $tail = substr($js, $pos, 200);
        $this->assertStringContainsString('e.preventDefault()', $tail, 'ранний выход должен отменять отправку');
    }

    /** Блок «Подзадачи» и форма добавления — общий partial для страницы и AJAX-ответа. */
    public function test_partial_is_shared_by_page_and_ajax_response(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../src/Controller/Council/TaskController.php');
        $this->assertStringContainsString('X-Requested-With', $controller, 'AJAX-ветка addSubtask не найдена');
        $this->assertStringContainsString('templates/council/_subtasks.php', $controller, 'AJAX-ответ не рендерит partial');

        ob_start();
        $t = ['subtasks' => [['id' => 7, 'done' => 0, 'title' => 'Осмотреть петли']]];
        $id = 1;
        $sort = 'created';
        require __DIR__ . '/../src/templates/council/_subtasks.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('class="sovet-sub-add"', $html, 'в partial нет формы добавления');
        $this->assertStringContainsString('action="/sovet/zadachi/1/podzadacha"', $html, 'форма добавления не на ту задачу');
        $this->assertStringContainsString('Осмотреть петли', $html);
        // Клиент проверяет фрагмент по маркеру — он должен быть в HTML.
        $this->assertStringContainsString('class="sovet-sub', $html);
    }
}