<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Sections, View};
use SkazResidents\Repository\ResidentsRepository;

/**
 * Справочник «Соседи» — карточки поместий и живущих в них жителей.
 * ПДн: доступен ТОЛЬКО вошедшим жителям (Auth::requireLogin). Публичной выдачи нет.
 */
final class ResidentsController
{
    use RequiresSection;   // выключённый админом раздел закрыт и по прямой ссылке

    public function __construct(
        private ResidentsRepository $repo = new ResidentsRepository()
    ) {}

    public function index(): void
    {
        $this->requireSection('sosedi');
        Auth::requireLogin();
        $q = trim((string) ($_GET['q'] ?? ''));
        $glades = $this->repo->gladeGroups($q !== '' ? $q : null);
        $lazy = self::isLazy($q);
        foreach ($glades as &$g) {
            $g['count'] = count($g['hhs']);
            // В ленивом режиме поместья в разметку не идут — за ними придут по клику.
            if ($lazy) { $g['hhs'] = []; }
        }
        unset($g);
        View::render('residents/directory', [
            'glades' => $glades,
            'stats'  => $this->repo->stats(),
            'q'      => $q,
            'lazy'   => $lazy,
        ], 'Наши соседи');
    }

    /**
     * Поместья одной поляны — фрагмент разметки для ленивого режима.
     * Гарды те же, что у страницы: это ПДн, и отдельный адрес их не открывает.
     */
    public function glade(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');   // ПДн — ни браузеру, ни прокси хранить незачем

        // Гарды те же, что у страницы, но отвечают кодом, а не переходом: ответ
        // подставляется в разметку как есть, и редирект вклеил бы в поляну целую
        // страницу входа или главную приложения.
        if (Auth::id() === null) {
            http_response_code(401);
            echo '<p class="res-meta">Сессия закончилась. Обновите страницу и войдите заново.</p>';
            return;
        }
        if (!Sections::isEnabled('sosedi')) {
            http_response_code(403);
            echo '<p class="res-meta">Раздел сейчас отключён в поселении.</p>';
            return;
        }

        $group = $this->repo->gladeGroup((string) ($_GET['p'] ?? ''));
        if ($group === null) {
            http_response_code(404);
            echo '<p class="res-meta">Поляна не найдена. Обновите страницу.</p>';
            return;
        }
        $hhs = $group['hhs'];
        $gnum = $group['num'];
        $open = false;                        // поместья приезжают свёрнутыми, как и на странице
        require __DIR__ . '/../templates/residents/_glade_body.php';
    }

    /**
     * Отдавать ли справочник по частям. Только мини-приложение MAX: там webview
     * заметно медленнее, а разметка всего поселения разом — это тысячи узлов,
     * которые всё равно скрыты до клика. При поиске режим выключен: результатов
     * мало и они сразу раскрыты, делить их на запросы незачем.
     */
    private static function isLazy(string $q): bool
    {
        return $q === '' && Auth::platform() === 'max';
    }
}
