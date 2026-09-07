<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Csrf, Flash, Validator, View};
use SkazResidents\Repository\{CouncilAgendaRepository, CouncilMemberRepository, CouncilMeetingRepository};

/**
 * Совместная повестка встречи Совета. Пункт может добавить любой член совета
 * (автор фиксируется). Удалять — автор пункта или дежурный/админ; сортировать и
 * отмечать «обсуждено» — дежурный председатель или админ.
 */
final class AgendaController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilAgendaRepository $agenda = new CouncilAgendaRepository(),
        private CouncilMemberRepository $members = new CouncilMemberRepository(),
        private CouncilMeetingRepository $meeting = new CouncilMeetingRepository()
    ) {}

    public function index(): void
    {
        CouncilAuth::requireLogin();
        View::render('council/agenda', [
            'items'    => $this->agenda->all(),
            'meeting'  => $this->meeting->get(),
            'me'       => CouncilAuth::name(),
            'isEditor' => $this->isEditor(),
        ], 'Повестка встречи', self::LAYOUT);
    }

    public function add(): void
    {
        $this->guard();
        $title = trim($_POST['title'] ?? '');
        if (!Validator::length($title, 2, 500)) {
            Flash::set('error', 'Пункт повестки: 2–500 символов.');
        } else {
            $this->agenda->add($title, CouncilAuth::name());
            Flash::set('success', 'Пункт добавлен в повестку.');
        }
        header('Location: /sovet/povestka');
    }

    public function delete(): void
    {
        $this->guard();
        $item = $this->agenda->findById((int) ($_POST['id'] ?? 0));
        if ($item && ($this->isEditor() || (string) $item['author'] === CouncilAuth::name())) {
            $this->agenda->delete((int) $item['id']);
            Flash::set('info', 'Пункт удалён.');
        } else {
            Flash::set('error', 'Удалить пункт может автор, дежурный председатель или админ.');
        }
        header('Location: /sovet/povestka');
    }

    public function toggle(): void
    {
        $this->guard();
        if (!$this->isEditor()) { Flash::set('error', 'Отмечать «обсуждено» может дежурный или админ.'); }
        else { $this->agenda->toggleDiscussed((int) ($_POST['id'] ?? 0)); }
        header('Location: /sovet/povestka');
    }

    public function move(): void
    {
        $this->guard();
        if ($this->isEditor()) {
            $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
            $this->agenda->move((int) ($_POST['id'] ?? 0), $dir);
        }
        header('Location: /sovet/povestka');
    }

    private function isEditor(): bool
    {
        return CouncilAuth::isAdmin() || $this->members->isDutyChair((int) CouncilAuth::id());
    }

    private function guard(): void
    {
        CouncilAuth::requireLogin();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }
    }
}
