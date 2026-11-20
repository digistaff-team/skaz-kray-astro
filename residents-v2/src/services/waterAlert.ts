import { db } from '../lib/db';
import { TelegramBot } from '../lib/telegramBot';
import { sendMaxMessage } from '../lib/max';
import {
  bridgeGapCm,
  roadGapCm,
  statusFor,
  formatDistance,
  formatMeters,
  toBsv,
  changeLabel,
  type WaterStatus,
} from './waterLevel';

/**
 * Оповещение группы жителей о подходе воды к мосту. Вызывается Vercel Cron
 * (раз в час) после записи замера.
 *
 * Логика идентична residents/src/Service/WaterAlert.php:
 *   — обстановка ухудшилась (calm → watch → alert), в том числе через ступень;
 *   — тревога держится дольше REPEAT_HOURS — напоминаем;
 *   — вода отступила до calm — отбой, один раз.
 * В остальных случаях молчим: иначе сообщение уходило бы каждый час.
 *
 * Скачок уровня больше JUMP_GUARD_CM за один шаг — повод не верить источнику
 * на слово. Но замалчивать такой скачок нельзя: ливневый паводок в горах
 * поднимает воду быстро. Поэтому при скачке к опасной обстановке пишем в
 * группу с пометкой «проверьте лично», а при скачке к спокойной — молчим.
 */

/** Повтор сообщения, пока держится тревога, часов. */
const REPEAT_HOURS = 6;
/** Скачок больше этого за шаг — верим с оговоркой, см. докблок. */
const JUMP_GUARD_CM = 300;

interface AlertState {
  status: WaterStatus;
  levelCm: number;
  notifiedAt: Date | null;
}

/**
 * Решить и, если надо, оповестить. Возвращает отправленный текст или null,
 * когда писать не о чем (обстановка не изменилась, история пуста, сбой источника).
 */
export async function checkWaterAlert(now: Date = new Date()): Promise<string | null> {
  const row = await db.waterLevelHistory.findFirst({ orderBy: { measuredAt: 'desc' } });
  if (!row) return null;

  const level = Number(row.levelCm);
  const gap = bridgeGapCm(level);
  const roadGap = roadGapCm(level);
  const status = statusFor(gap, roadGap);

  const stateRow = await db.waterAlertState.findFirst({ where: { id: 1 } });
  const prev: AlertState | null = stateRow
    ? {
        status: stateRow.status as WaterStatus,
        levelCm: Number(stateRow.levelCm),
        notifiedAt: stateRow.notifiedAt,
      }
    : null;

  const jumped = prev !== null && Math.abs(level - prev.levelCm) > JUMP_GUARD_CM;
  if (jumped) {
    console.error(
      `WaterAlert: скачок уровня ${prev!.levelCm.toFixed(2)} → ${level.toFixed(2)} см — ${
        status === 'calm' ? 'молчим, похоже на сбой источника' : 'оповещаем с оговоркой'
      }`
    );
    // Скачок «улучшил» картину — отбой по такому замеру не даём.
    if (status === 'calm') {
      await rememberState(status, level);
      return null;
    }
  }

  if (!shouldNotify(status, prev, now)) {
    await rememberState(status, level);
    return null;
  }

  const change = Number(row.change24h);
  const text = message(status, level, change, jumped, isImproving(status, prev), roadGap);
  await send(text);
  await rememberState(status, level, now);
  return text;
}

/** Запомнить текущее состояние в БД. */
async function rememberState(status: WaterStatus, levelCm: number, notifiedAt?: Date): Promise<void> {
  await db.waterAlertState.upsert({
    where: { id: 1 },
    create: { id: 1, status, levelCm, notifiedAt: notifiedAt ?? null },
    update: { status, levelCm, notifiedAt: notifiedAt ?? null },
  });
}

/** Обстановка стала спокойнее, чем была. */
function isImproving(status: WaterStatus, prev: AlertState | null): boolean {
  if (prev === null) return false;
  const rank: Record<WaterStatus, number> = { calm: 0, watch: 1, alert: 2 };
  return rank[status] < rank[prev.status];
}

/** Нужно ли отправлять сообщение? */
function shouldNotify(status: WaterStatus, prev: AlertState | null, now: Date): boolean {
  // Первый запуск: о спокойной воде сообщать не о чем, о тревожной — сразу пишем.
  if (prev === null) return status !== 'calm';

  const was = prev.status;
  if (was === status) {
    if (status !== 'alert') return false;
    if (prev.notifiedAt === null) return true; // состояние знали, а написать не успели
    const age = now.getTime() - prev.notifiedAt.getTime();
    return age >= REPEAT_HOURS * 3600 * 1000; // тревога затянулась — напоминаем
  }

  return true; // и ухудшение, и отбой достойны сообщения
}

/** Сформировать текст сообщения. */
function message(
  status: WaterStatus,
  levelCm: number,
  changeCm: number,
  suspect = false,
  improving = false,
  roadGapCm?: number
): string {
  const gap = bridgeGapCm(levelCm);
  const gapText = formatDistance(Math.abs(gap));
  let tail = `Уровень ${formatMeters(toBsv(levelCm), 2)} БСВ, ${changeLabel(changeCm)}.`;

  // Замер после резкого скачка мог прийти и от сбоя источника — просим проверить.
  if (suspect) {
    tail += ' Уровень изменился скачком — проверьте обстановку лично.';
  }

  // «Мост под водой» — только когда вода превысила полотно дороги (36,16 м БСВ).
  if (roadGapCm !== undefined && roadGapCm <= 0) {
    const roadGapText = formatDistance(Math.abs(roadGapCm));
    return `🚨 Мост под водой: уровень выше полотна дороги на ${roadGapText}. ${tail}`;
  }
  if (gap <= 0) {
    return `🚨 Мост в воде: до нижней кромки ${gapText}. ${tail}`;
  }
  if (gap <= 30) {
    return `🚨 Вода у моста: до нижней кромки ${gapText}. ${tail}`;
  }

  switch (status) {
    case 'alert':
      return `⚠️ Вода у моста: до нижней кромки ${gapText}. ${tail}`;
    case 'watch':
      return improving
        ? `🌊 Вода уходит: до нижней кромки ${gapText}. ${tail}`
        : `⚠️ Шебш поднимается: до нижней кромки моста ${gapText}. ${tail}`;
    default:
      return `✅ Вода спала: до нижней кромки моста ${gapText}. ${tail}`;
  }
}

/**
 * Канал оповещений в Telegram и группа в MAX (адреса — в .env).
 * Отдельный ключ SKAZKRAY_WATER_ALERT_CHAT_ID: про воду пишем в свой канал,
 * а не в общий чат жителей. Не задан — падаем обратно на общий чат.
 */
async function send(text: string): Promise<void> {
  // Telegram
  const tgToken = process.env.SKAZKRAY_BOT_TOKEN || '';
  let tgChat = process.env.SKAZKRAY_WATER_ALERT_CHAT_ID || '';
  if (tgChat === '') {
    tgChat = process.env.SKAZKRAY_GROUP_CHAT_ID || '';
  }
  if (tgToken !== '' && tgChat !== '') {
    await TelegramBot.sendMessage(tgToken, tgChat, text);
  }

  // MAX
  const maxToken = process.env.SKAZKRAY_MAX_BOT_TOKEN || '';
  const maxChat = process.env.SKAZKRAY_MAX_GROUP_CHAT_ID || '';
  if (maxToken !== '' && maxChat !== '') {
    // MAX не поддерживает отправку в чат напрямую — только личное сообщение.
    // Поэтому пропускаем MAX для групповых алертов (как и в легаси).
    console.log('WaterAlert: MAX-группа не поддерживается для алертов');
  }
}