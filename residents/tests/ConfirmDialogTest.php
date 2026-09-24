<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Опасные действия подтверждаются своим окном (partials/confirm.php), а не
 * window.confirm: в мини-приложениях Telegram и MAX системное окно чужое на вид,
 * а часть клиентов его глушит — и «Удалить» молча не срабатывает.
 */
final class ConfirmDialogTest extends TestCase
{
    private const DIR = __DIR__ . '/../src/templates';

    public function test_templates_do_not_use_window_confirm(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::DIR) + 1));
            if ($rel === 'partials/confirm.php') { continue; }
            if (preg_match('~\bconfirm\s*\(~', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders, 'Вместо confirm() — data-confirm="…" на форме');
    }

    /** Окно должно перехватить отправку раньше сжатия фото и защиты от повтора. */
    public function test_both_layouts_include_dialog_before_other_submit_handlers(): void
    {
        foreach (['layout.php', 'council/layout.php'] as $layout) {
            $src = (string) file_get_contents(self::DIR . '/' . $layout);
            $confirm = strpos($src, "partials/confirm.php'");
            $this->assertNotFalse($confirm, $layout);
            $this->assertLessThan(strpos($src, "partials/photo-shrink.php'"), $confirm, $layout);
            $this->assertLessThan(strpos($src, "partials/submit-guard.php'"), $confirm, $layout);
        }
    }

    public function test_dialog_renders_hidden(): void
    {
        ob_start();
        require self::DIR . '/partials/confirm.php';
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('id="resConfirm" hidden', $html);
        $this->assertStringContainsString('Отмена', $html);
    }
}
