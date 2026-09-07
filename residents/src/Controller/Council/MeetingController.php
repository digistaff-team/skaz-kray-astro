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
        $meeting = $this->meeting->get();
        // Дата ещё не выбрана или прошедшая встреча → предлагаем ближайший будущий
        // понедельник (UTC+3): сегодня-понедельник → сегодня, иначе следующий.
        $startsAt = (string) ($meeting['startsAt'] ?? '');
        if ($startsAt === '' || self::isPastDay($startsAt)) {
            $meeting['startsAt'] = self::upcomingMonday('18:00');
            $meeting['endsAt']   = self::upcomingMonday('20:00');
        }
        View::render('council/meeting_edit', [
            'meeting'     => $meeting,
            'agendaText'  => $this->meeting->agendaText(),
            'errors'      => [],
        ], 'Редактирование встречи', self::LAYOUT);
    }

    public function save(): void
    {
        $this->requireEditor();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $startsRaw     = trim($_POST['starts_at'] ?? '');
        $endsRaw       = trim($_POST['ends_at'] ?? '');
        $place         = trim($_POST['place'] ?? '');
        $dutyChair     = trim($_POST['duty_chair'] ?? '');
        $dutySecretary = trim($_POST['duty_secretary'] ?? '');
        $agenda        = (string) ($_POST['agenda'] ?? '');

        $startDb = self::parseLocal($startsRaw);
        $endDb   = self::parseLocal($endsRaw);

        $errors = [];
        if ($startDb === null)                       { $errors['starts_at'] = 'Укажите дату и время начала.'; }
        if ($endsRaw !== '' && $endDb === null)      { $errors['ends_at'] = 'Неверная дата и время окончания.'; }
        if ($startDb !== null && $endDb !== null && strtotime($endDb) < strtotime($startDb)) {
            $errors['ends_at'] = 'Окончание не может быть раньше начала.';
        }
        if (!Validator::length($place, 2, 200))      { $errors['place'] = 'Место: 2–200 символов.'; }
        if ($dutyChair !== '' && !Validator::length($dutyChair, 2, 160))         { $errors['duty_chair'] = 'До 160 символов.'; }
        if ($dutySecretary !== '' && !Validator::length($dutySecretary, 2, 160)) { $errors['duty_secretary'] = 'До 160 символов.'; }

        if ($errors) {
            View::render('council/meeting_edit', [
                'meeting' => [
                    'startsAt' => $startsRaw, 'endsAt' => $endsRaw, 'place' => $place,
                    'dutyChair' => $dutyChair, 'dutySecretary' => $dutySecretary, 'agenda' => [],
                ],
                'agendaText' => $agenda,
                'errors'     => $errors,
            ], 'Редактирование встречи', self::LAYOUT);
            return;
        }

        $this->meeting->update($startDb, $endDb, $place, $dutyChair, $dutySecretary, $agenda);
        Flash::set('success', 'Информация о ближайшем собрании обновлена.');
        header('Location: /sovet');
    }

    /** Ближайший будущий понедельник (UTC+3) как значение для datetime-local. */
    private static function upcomingMonday(string $time): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('Europe/Moscow'));
        $dow = (int) $dt->format('N');       // 1=Пн … 7=Вс
        $add = $dow === 1 ? 0 : (8 - $dow);  // Пн → сегодня, иначе до следующего Пн
        if ($add > 0) { $dt->modify("+{$add} day"); }
        [$h, $m] = array_map('intval', explode(':', $time));
        $dt->setTime($h, $m);
        return $dt->format('Y-m-d\TH:i');
    }

    /** День встречи уже прошёл (по календарю, UTC+3)? */
    private static function isPastDay(string $local): bool
    {
        $ts = strtotime($local);
        if ($ts === false) { return false; }
        $today = (new \DateTime('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        return date('Y-m-d', $ts) < $today;
    }

    /** datetime-local ('Y-m-dTH:i') → формат БД ('Y-m-d H:i:s'), либо null. */
    private static function parseLocal(string $local): ?string
    {
        if ($local === '') { return null; }
        $ts = strtotime($local);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * Передача роли Дежурного председателя другому члену совета.
     * Доступ: текущий дежурный или админ. У прежнего дежурного роль становится
     * «Член Совета», у выбранного — «Дежурный председатель» (setDutyChair
     * сбрасывает флаг у всех и ставит одному).
     */
    public function handoff(): void
    {
        $this->requireEditor();
        if (!Csrf::check($_POST['_csrf'] ?? null)) { http_response_code(400); exit('Неверный токен формы.'); }

        $newId  = (int) ($_POST['member_id'] ?? 0);
        $member = $newId > 0 ? $this->members->findById($newId) : null;

        if (!$member) {
            Flash::set('error', 'Выберите члена совета для передачи дежурства.');
        } elseif ($member['status'] !== 'active' || ($member['role'] ?? '') === 'admin') {
            Flash::set('error', 'Передать дежурство можно только активному члену совета.');
        } else {
            $this->members->setDutyChair($newId);
            Flash::set('success', "Роль Дежурного председателя передана: {$member['name']}.");
        }
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
