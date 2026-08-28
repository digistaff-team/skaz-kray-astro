<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Repository\{DiaryRepository, ProductRepository};

final class CabinetController
{
    public function __construct(
        private DiaryRepository $diary = new DiaryRepository(),
        private ProductRepository $products = new ProductRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $familyId = Auth::id();
        View::render('cabinet/index', [
            'entries'  => $this->diary->listByFamily($familyId),
            'products' => $this->products->listByFamily($familyId),
        ], 'Личный кабинет');
    }
}
