<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Auth, Config, Csrf};
use SkazResidents\Controller\BroadcastController;
use SkazResidents\Repository\FamilyRepository;

/**
 * Рассылка в Telegram: рендер формы, валидация текста, отказ не-админу
 * и выборка адресатов (FamilyRepository::listWithTelegram).
 * Отправка сообщений здесь не проверяется — сеть не дёргаем (Config::set([])),
 * сам цикл доставки уходит в BotNotify::afterResponse (см. BroadcastController).
 */
final class BroadcastControllerTest extends TestCase
{
    private BroadcastController $ctrl;

    protected function setUp(): void
    {
        Config::set([]);   // без telegram → сеть не дёргается
        make_test_db();
        $_SESSION = [];
        $_POST = [];
        $this->ctrl = new BroadcastController();
        if (function_exists('http_response_code')) {
            @http_response_code(200);   // сброс после предыдущего теста (exit в requireAdmin меняет код)
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    /** Страница рассылки: форма с textarea и списком адресатов. */
    public function test_index_renders_form_for_admin(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $html = $this->capture(static fn() => $ctrl->index());
        $this->assertStringContainsString('action="/poselenie/moderation/rassylka"', $html);
        $this->assertStringContainsString('name="text"', $html);
        $this->assertStringContainsString('name="target"', $html);
        $this->assertStringContainsString('name="family_id"', $html);
    }

    /** Пустой текст и текст длиннее 4000 знаков — рендер формы с ошибкой, без отправки. */
    public function test_send_validates_text(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $_POST = ['_csrf' => Csrf::token(), 'text' => '   ', 'target' => 'all_tg', 'family_id' => 0];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('не может быть пустым', $html);

        $_POST = ['_csrf' => Csrf::token(), 'text' => str_repeat('а', 4001), 'target' => 'all_tg', 'family_id' => 0];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('длиннее 4000', $html);
    }

    /** Редактор (не админ) от рассылки отбивается 403 и не доходит до отправки. */
    public function test_non_admin_is_rejected(): void
    {
        $this->loginAs('editor');
        $ctrl = $this->ctrl;
        try {
            $this->capture(static fn() => $ctrl->index());
        } catch (\Throwable $e) {
            // Auth::requireAdmin() завершает запрос через exit — PHPUnit его ловит.
        }
        $code = @http_response_code();
        if (!is_int($code)) {
            $code = 0;
        }
        $this->assertSame(403, $code);
    }

    /** Адресаты — только активные семьи с привязанным Telegram. */
    public function test_list_with_telegram_filters_by_status_and_binding(): void
    {
        $pdo = make_test_db();
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status, role) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Семья А', 'active', 'resident'),
            (2, 'b@sk.ru', 222, 'x', 'Семья Б', 'pending', 'resident'),
            (3, 'c@sk.ru', NULL, 'x', 'Семья В', 'active', 'resident')");
        $rows = (new FamilyRepository())->listWithTelegram();
        $this->assertCount(1, $rows);
        $this->assertSame('Семья А', (string) $rows[0]['name']);
        $this->assertSame(111, (int) $rows[0]['telegram_id']);
    }

    private function loginAs(string $role): void
    {
        $_SESSION = ['family_id' => 1, 'role' => $role, 'family_name' => 'Тест'];
    }

    /** Выполнить контроллер, забрав его HTML-вывод. */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}