/**
 * Ротация Дежурного председателя — порт CouncilDutyRotation::advance().
 *
 * Ротация привязана к ПОСЛЕДОВАТЕЛЬНОСТИ проведённых встреч, а не к календарю:
 * позиция хранится в council_meeting.rotation_index и двигается на +1 только
 * когда встреча состоялась.
 *
 * advance() — полный цикл:
 *  1. +7 дней к дате встречи
 *  2. +1 к rotationIndex
 *  3. Новый дежурный (isDutyChair + подпись в карточке)
 *  4. reset dutyAckAt (новый дежурный ещё не подтвердил)
 *  5. rollOver повестки (обсуждённое удалить, остальное перенести)
 *  6. Уведомить нового дежурного (если есть telegram_id)
 */
import { db } from './db';
import { dutyNameForIndex, DUTY_ORDER } from './councilData';
import { applyDutyRotation } from './councilDuty';
import { notifyNewChair } from './dutyNotify';
import { formatMeetingDate } from '../routes/council/meeting';

export async function advance(): Promise<{ date: string; chair: string } | null> {
  const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
  if (!meeting?.startsAt) {
    console.error('advance: нет даты встречи');
    return null;
  }

  // +7 дней от текущей даты встречи
  const dt = new Date(meeting.startsAt.getTime() + 7 * 24 * 60 * 60 * 1000);

  const newIdx = ((meeting.rotationIndex ?? 0) + 1) % DUTY_ORDER.length;
  const chair = dutyNameForIndex(newIdx);

  // Новая встреча: та же площадка, новый дежурный. Секретарь НЕ ротируется.
  await db.councilMeeting.upsert({
    where: { id: 1 },
    create: {
      id: 1,
      startsAt: dt,
      meetingDate: formatMeetingDate(dt),
      place: meeting.place,
      dutyChair: chair,
      dutySecretary: meeting.dutySecretary ?? '',
      rotationIndex: newIdx,
    },
    update: {
      startsAt: dt,
      meetingDate: formatMeetingDate(dt),
      place: meeting.place,
      dutyChair: chair,
      dutySecretary: meeting.dutySecretary ?? '',
      rotationIndex: newIdx,
      dutyAckAt: null,          // новый дежурный ещё не подтвердил
      dutyAckChatId: null,
      dutyAckMsgId: null,
    },
  });

  // sync is_duty_chair flag
  await applyDutyRotation();

  // Повестка: обсуждённое удалить, остальное перенести
  await db.councilAgendaItem.deleteMany({ where: { discussed: true } });
  await db.councilAgendaItem.updateMany({
    where: { discussed: false },
    data: { carriedOver: true },
  });

  // Уведомить нового дежурного
  const when = formatMeetingDate(dt);
  const place = meeting.place;
  const sent = await notifyNewChair(chair, when, place, newIdx);
  if (sent) {
    console.log(`advance: уведомление отправлено — ${chair}`);
  } else {
    console.log(`advance: уведомление НЕ отправлено — ${chair} (нет telegram_id или ошибка)`);
  }

  return { date: when, chair };
}
