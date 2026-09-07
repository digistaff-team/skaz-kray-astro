<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{CouncilAuth, Csrf, Flash, Validator, View};
use SkazResidents\Repository\{CouncilMeetingRepository, CouncilMemberRepository};

/**
 * Редактирование карточки «Ближайшее собрание».
 * Доступ: текущий Дежурный председатель (флаг is_duty_chair) ИЛИ администратор
 * совета. Роль дежурного переходящая, назначается в /sovet/upravlenie.
 */
final class MeetingController
{
    private const LAYOUT = 'council/layout';

    public function __construct(
        private CouncilMeetingRepository $meeting = new CouncilMeetingRepository(),
        private CouncilMemberRepository $members = new CouncilMemberRepository()
    ) {}

    public function showEdit(): void
    {
        $this->requireEditor();
        View::render('council/meeting_edit', [
            'meeting'     => $this->meeting->get(),
            'agendaText'  => $this->meeting->agendaText(),
            'errors'      => [],
        ], 'Редактирование встречи', self::LAYOUT);
    }

    public function save(): void
    {
        $this->requireEditor();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $date          = trim($_POST['date'] ?? '');
        $place         = trim($_POST['place'] ?? '');
        $dutyChair     = trim($_POST['duty_chair'] ?? '');
        $dutySecretary = trim($_POST['duty_secretary'] ?? '');
        $agenda        = (string) ($_POST['agenda'] ?? '');

        $errors = [];
        if (!Validator::length($date, 3, 160))       { $errors['date'] = 'Дата и время: 3–160 символов.'; }
        if (!Validator::length($place, 2, 200))      { $errors['place'] = 'Место: 2–200 символов.'; }
        if ($dutyChair !== '' && !Validator::length($dutyChair, 2, 160))         { $errors['duty_chair'] = 'До 160 символов.'; }
        if ($dutySecretary !== '' && !Validator::length($dutySecretary, 2, 160)) { $errors['duty_secretary'] = 'До 160 символов.'; }

        if ($errors) {
            View::render('council/meeting_edit', [
                'meeting' => [
                    'date' => $date, 'place' => $place, 'dutyChair' => $dutyChair,
                    'dutySecretary' => $dutySecretary, 'agenda' => [],
                ],
                'agendaText' => $agenda,
                'errors'     => $errors,
            ], 'Редактирование встречи', self::LAYOUT);
            return;
        }

        $this->meeting->update($date, $place, $dutyChair, $dutySecretary, $agenda);
        Flash::set('success', 'Информация о ближайшем собрании обновлена.');
        header('Location: /sovet');
    }

    /** Дежурный председатель или админ; иначе — назад на главную с сообщением. */
    private function requireEditor(): void
    {
        CouncilAuth::requireLogin();
        $isEditor = CouncilAuth::isAdmin() || $this->members->isDutyChair((int) CouncilAuth::id());
        if (!$isEditor) {
            Flash::set('error', 'Редактировать встречу может только Дежурный председатель или администратор совета.');
            header('Location: /sovet');
            exit;
        }
    }
}
