<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Repository\{DiaryRepository, ProductRepository, ToolRepository, BookRepository, TripRepository};

/**
 * Личный кабинет жителя (app-экран по макету «Мобильное приложение»): дневник
 * поместья, блок «Мои вещи в общем» (сводка инструментов/книг/поездок со счётчиками)
 * и управление своими товарами/услугами. Инструменты/книги/поездки управляются на
 * своих страницах /moi — здесь только сводные строки-ссылки.
 */
final class CabinetController
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository(),
        private ToolRepository $tools = new ToolRepository(),
        private BookRepository $books = new BookRepository(),
        private TripRepository $trips = new TripRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $familyId = Auth::id();

        $myTools = $this->tools->listByFamily($familyId);
        $myBooks = $this->books->listByFamily($familyId);
        $myTrips = $this->trips->listByDriver($familyId);

        $out = static fn(array $rows): int => count(array_filter($rows, static fn($r) => ($r['status'] ?? '') === 'on_loan'));
        $mine = static function (int $n, int $out, string $outWord, string $empty): string {
            if ($n === 0) { return $empty; }
            $s = $n . ' своих';
            if ($out > 0) { $s .= ' · ' . $out . ' ' . $outWord; }
            return $s;
        };

        $things = [
            ['name' => 'Инструменты', 'href' => '/poselenie/instrumenty/moi',
             'meta' => $mine(count($myTools), $out($myTools), 'у соседа', 'пока не делитесь')],
            ['name' => 'Книги', 'href' => '/poselenie/knigi/moi',
             'meta' => $mine(count($myBooks), $out($myBooks), 'на руках', 'полка пуста')],
            ['name' => 'Поездки', 'href' => '/poselenie/poezdki/moi',
             'meta' => count($myTrips) > 0
                 ? count($myTrips) . ' ' . plural_ru(count($myTrips), 'поездка', 'поездки', 'поездок')
                 : 'нет предложенных'],
        ];

        View::render('cabinet/index', [
            'entries'  => $this->diary->listByFamily($familyId),
            'products' => $this->products->listByFamily($familyId),
            'things'   => $things,
        ], 'Мой кабинет');
    }
}
