<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{View, Config};
use SkazResidents\Repository\{DiaryRepository, ProductRepository, ImageRepository};

final class PublicController
{
    private const PER_PAGE = 10;

    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository(),
        private ImageRepository $images = new ImageRepository()
    ) {}

    /**
     * Внешняя публичная лента (без авторизации) — только записи, отмеченные
     * семьёй галочкой «Опубликовать на внешнем сайте» (is_public=1). Полная
     * лента для жителей — DiaryController::feed() (требует входа).
     */
    public function diaryList(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $entries = $this->diary->listPublishedPublic(self::PER_PAGE, $offset);
        foreach ($entries as &$e) {
            $e['images'] = $this->images->listFor('entry', (int) $e['id']);
        }
        unset($e);
        View::render('public/diary_list', [
            'entries' => $entries,
            'page' => $page,
            'total' => $this->diary->countPublishedPublic(),
            'perPage' => self::PER_PAGE,
            'navActive' => 'stati',
        ], 'Дневники поместий', 'public/layout');
    }

    public function diaryShow(array $params): void
    {
        $entry = $this->diary->findPublishedPublicById((int) $params['id']);
        if (!$entry) { http_response_code(404); View::render('public/notfound', ['navActive' => 'stati'], 'Запись не найдена', 'public/layout'); return; }
        $entry['images'] = $this->images->listFor('entry', (int) $entry['id']);
        View::render('public/diary_show', ['entry' => $entry, 'navActive' => 'stati'], $entry['title'], 'public/layout');
    }

    public function marketList(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $items = $this->products->listPublished(self::PER_PAGE, $offset);
        foreach ($items as &$p) {
            $p['images'] = $this->images->listFor('product', (int) $p['id']);
        }
        unset($p);
        View::render('public/market_list', [
            'items' => $items,
            'page' => $page,
            'total' => $this->products->countPublished(),
            'perPage' => self::PER_PAGE,
            'navActive' => 'yarmarka',
        ], 'Ярмарка', 'public/layout');
    }

    public function marketShow(array $params): void
    {
        $p = $this->products->findPublishedById((int) $params['id']);
        if (!$p) { http_response_code(404); View::render('public/notfound', ['navActive' => 'yarmarka'], 'Товар не найден', 'public/layout'); return; }
        $p['images'] = $this->images->listFor('product', (int) $p['id']);
        View::render('public/market_show', ['product' => $p, 'navActive' => 'yarmarka'], $p['title'], 'public/layout');
    }

    public static function uploadsUrl(): string
    {
        return rtrim((string) Config::get('uploads_url'), '/');
    }
}
