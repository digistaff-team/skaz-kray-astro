<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Страница входа и повторный вход без пароля в мини-приложениях.
 *
 * Сам переход — клиентский скрипт, в PHPUnit его не выполнить. Сторожим
 * разметку, на которой он держится: адрес возврата безопасно попадает в
 * скрипт, а явный выход отличим от истёкшей сессии.
 */
final class LoginRedirectTest extends TestCase
{
    private function render(?string $next): string
    {
        $old = [];
        $error = null;
        ob_start();
        require __DIR__ . '/../src/templates/auth/login.php';
        return (string) ob_get_clean();
    }

    public function test_return_path_reaches_the_script(): void
    {
        $html = $this->render('/poselenie/instrumenty/12');
        $this->assertStringContainsString('var next = "/poselenie/instrumenty/12";', $html);
    }

    public function test_without_return_path_script_gets_null(): void
    {
        $this->assertStringContainsString('var next = null;', $this->render(null));
    }

    public function test_return_path_cannot_break_out_of_script(): void
    {
        // Через safeNext сюда такое не пройдёт, но шаблон не должен на это полагаться.
        $html = $this->render('/poselenie/</script><script>alert(1)</script>');
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
    }

    public function test_explicit_logout_is_recognised(): void
    {
        // Иначе в мини-приложении «Выход» тут же впускал бы обратно.
        $html = $this->render(null);
        $this->assertStringContainsString('vyshli=1', $html);
        $this->assertStringContainsString("removeItem('skazTgInitData')", $html);
    }

    public function test_hint_for_messenger_residents(): void
    {
        $this->assertStringContainsString('пароль не нужен', $this->render(null));
    }
}
