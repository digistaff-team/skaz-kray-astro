<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, View};
use SkazResidents\Repository\ResidentsRepository;

/**
 * Справочник «Соседи» — карточки поместий и живущих в них жителей.
 * ПДн: доступен ТОЛЬКО вошедшим жителям (Auth::requireLogin). Публичной выдачи нет.
 */
final class ResidentsController
{
    public function __construct(
        private ResidentsRepository $repo = new ResidentsRepository()
    ) {}

    public function index(): void
    {
        Auth::requireLogin();
        $q = trim((string) ($_GET['q'] ?? ''));
        View::render('residents/directory', [
            'households' => $this->repo->grouped($q !== '' ? $q : null),
            'stats'      => $this->repo->stats(),
            'q'          => $q,
        ], 'Наши соседи');
    }
}
