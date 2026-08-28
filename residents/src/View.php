<?php
declare(strict_types=1);
namespace SkazResidents;

final class View
{
    /**
     * Рендер шаблона внутри layout. $data извлекается в область видимости шаблона.
     * $layout — имя файла-обёртки в templates/ (по умолчанию 'layout' — раздел жителей;
     * раздел совета передаёт 'council/layout').
     */
    public static function render(string $template, array $data = [], string $title = '', string $layout = 'layout'): void
    {
        extract($data, EXTR_SKIP);
        $templateFile = __DIR__ . '/templates/' . $template . '.php';
        ob_start();
        require $templateFile;
        $content = ob_get_clean();
        require __DIR__ . '/templates/' . $layout . '.php';
    }

    /** Экранирование для вывода в HTML. */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
