/**
 * Ротация Дежурного председателя — порт CouncilDutyRotation.php.
 *
 * Ротация привязана к ПОСЛЕДОВАТЕЛЬНОСТИ проведённых встреч, а не к календарю:
 * позиция хранится в council_meeting.rotation_index и двигается на +1 только
 * когда встреча состоялась. Перенос даты НЕ сдвигает очередь.
 *
 * apply() — синхронизирует дежурного под текущий индекс (флаг is_duty_chair +
 * подпись в карточке). Вызывается при сохранении встречи.
 */
import { db } from './db';
import { dutyNameForIndex } from './councilData';

/**
 * Синхронизировать дежурного под сохранённый индекс. Возвращает имя или null
 * (нет такого члена в council_members).
 */
export async function applyDutyRotation(): Promise<string | null> {
  const meeting = await db.councilMeeting.findUnique({ where: { id: 1 } });
  const name = dutyNameForIndex(meeting?.rotationIndex ?? 0);

  const member = await db.councilMember.findFirst({ where: { name } });
  if (!member) return null;

  await setDutyChair(member.id);
  await db.councilMeeting.update({
    where: { id: 1 },
    data: { dutyChair: name },
  }).catch(() => undefined); // строки ещё может не быть — подпись проставит вызывающий
  return name;
}

/**
 * Назначить дежурного председателя: роль переходящая и единоличная —
 * сбрасываем флаг у всех и ставим одному (порт setDutyChair).
 */
export async function setDutyChair(memberId: number): Promise<void> {
  await db.$transaction([
    db.councilMember.updateMany({ data: { isDutyChair: false } }),
    ...(memberId > 0
      ? [db.councilMember.update({ where: { id: memberId }, data: { isDutyChair: true } })]
      : []),
  ]);
}