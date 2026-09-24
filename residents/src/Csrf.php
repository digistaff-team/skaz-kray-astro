<?php
declare(strict_types=1);
namespace SkazResidents;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    /** HTML-поле для форм. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    /**
     * Проверка токена на входе в POST-обработчик: либо пропускает дальше, либо
     * отвечает 400 и обрывает запрос.
     *
     * Раньше здесь везде стоял «exit('Неверный токен формы.')», и житель, приложивший
     * к товару пару тяжёлых снимков, получал белую страницу с обвинением в подделке
     * формы: PHP выбрасывает слишком большое тело целиком (post_max_size), вместе с
     * ним пропадает и _csrf — снаружи это неотличимо от настоящей подделки.
     */
    public static function guard(): void
    {
        if (self::check($_POST['_csrf'] ?? null)) { return; }
        http_response_code(400);
        if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
        echo self::rejectionPage(self::bodyDropped());
        exit;
    }

    /**
     * Тело запроса не доехало целиком: PHP отбросил его по post_max_size, поэтому
     * пусты разом и поля, и файлы — при том, что браузер что-то посылал
     * (Content-Length > 0). Так выглядит форма с фото тяжелее лимита.
     */
    public static function bodyDropped(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST'
            && $_POST === []
            && $_FILES === []
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /**
     * Страница отказа — самодостаточная, без layout: guard() зовут и из раздела
     * жителей, и из совета, а у них разные обёртки.
     */
    public static function rejectionPage(bool $bodyDropped): string
    {
        [$title, $text] = $bodyDropped
            ? [
                'Файлы слишком тяжёлые',
                'Форма не отправилась: вложения весят больше, чем принимает сервер'
                    . self::limitNote() . '. Вернитесь назад и приложите файлы поменьше'
                    . ' или по одному — текст формы сохранится.',
            ]
            : [
                'Форма устарела',
                'Похоже, страница была открыта слишком долго или вход выполнен заново.'
                    . ' Вернитесь назад, обновите страницу и отправьте ещё раз.',
            ];

        return '<!doctype html><html lang="ru"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . View::e($title) . '</title>'
            . '<body style="margin:0;padding:2rem 1rem;font:16px/1.5 system-ui,sans-serif;color:#2b2b2b">'
            . '<div style="max-width:32rem;margin:0 auto">'
            . '<h1 style="font-size:1.35rem;margin:0 0 .75rem">' . View::e($title) . '</h1>'
            . '<p style="margin:0 0 1.5rem">' . View::e($text) . '</p>'
            . '<button type="button" onclick="history.back()"'
            . ' style="font:inherit;padding:.6rem 1.2rem;border:0;border-radius:.4rem;'
            . 'background:#4c7a34;color:#fff;cursor:pointer">Вернуться назад</button>'
            . '</div></body></html>';
    }

    /** « (не больше 8 МБ на одну отправку)» — если лимит удалось прочитать. */
    private static function limitNote(): string
    {
        $bytes = self::shorthandToBytes((string) ini_get('post_max_size'));
        if ($bytes === null) { return ''; }
        $mb = $bytes / 1048576;
        $shown = $mb >= 10 ? (string) (int) round($mb) : rtrim(rtrim(number_format($mb, 1, ',', ''), '0'), ',');
        return ' (не больше ' . $shown . ' МБ на одну отправку)';
    }

    /** «8M», «512K», «1G» из php.ini — в байты. null, если лимит не задан. */
    public static function shorthandToBytes(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^(\d+)\s*([KMG]?)$/i', $raw, $m)) { return null; }
        $n = (int) $m[1];
        if ($n <= 0) { return null; } // 0 и -1 — «без ограничения», говорить не о чем
        return $n * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }
}
