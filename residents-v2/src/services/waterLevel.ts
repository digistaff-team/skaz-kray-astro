import { db } from '../lib/db';

/**
 * Уровень воды в Шебше — порт residents/src/Service/WaterLevel.php.
 *
 * Источник замеров — приложение shebsh-water-level на Vercel:
 * GET /api/water-level → {"water_level":см,"change_24h":см}. Историю ведём свою
 * (water_level_history). Чужой /api/history не используем — он живёт в Vercel KV
 * и уже отдавал 502.
 *
 * Вся арифметика — в системе высот Балтийской (БСВ), константы совпадают с
 * constants.ts того приложения. level_cm — сантиметры от нуля гидропоста,
 * может быть отрицательным. При правке любой отметки сверяйте панель целиком.
 */

/** Ноль гидропоста, м БСВ. */
export const GAUGE_ZERO_BSV = 38.158;
/** Нижняя кромка моста — «НЯ затопления», м БСВ. */
export const BRIDGE_BSV = 35.16;
/** Опасная отметка — «ОЯ затопления», м БСВ. */
export const HIGH_FLOOD_BSV = 36.16;

/** Отметки в той же шкале, что level_cm (см от нуля гидропоста). */
export const BRIDGE_CM = -300;      // (35.160 - 38.158) * 100
export const HIGH_FLOOD_CM = -200;  // (36.160 - 38.158) * 100

/**
 * Пороги запаса до кромки моста, см: ближе CALM — предупреждаем, ближе WATCH —
 * тревога. Заданы по опыту жителей: предупреждать за 3 м, тревога за 1 м.
 */
const CALM_GAP_CM = 300;
const WATCH_GAP_CM = 100;

/** Замер старше этого — при открытии панели спрашиваем источник заново, мин. */
const STALE_MINUTES = 90;

/** Глубина диаграммы, дней. */
const CHART_DAYS = 30;

export type WaterStatus = 'calm' | 'watch' | 'alert';

export interface WaterPanel {
  level: number | null;
  levelBsv?: number;
  levelLabel?: string;
  gap?: number;
  gapLabel?: string;
  flooded?: boolean;
  status?: WaterStatus;
  change?: number;
  changeLabel?: string;
  measuredAt?: string;
  chart: ChartGeometry | null;
}

export interface ChartPoint {
  date: string;
  levelCm: number;
  change24h: number;
}

export interface ChartGeometry {
  w: number;
  h: number;
  poly: string;
  area: string;
  rangeLabel: string;
  firstDate: string;
  lastDate: string;
  days: number;
  marks: Array<{ y: number; label: string }>;
}

/** Уровень в метрах БСВ. */
export function toBsv(levelCm: number): number {
  return levelCm / 100 + GAUGE_ZERO_BSV;
}

/**
 * Запас до нижней кромки моста, см: больше нуля — вода ниже кромки,
 * ноль и меньше — вода на кромке или выше (мост под водой).
 */
export function bridgeGapCm(levelCm: number): number {
  return BRIDGE_CM - levelCm;
}

/** 'calm' | 'watch' | 'alert' по запасу до моста. */
export function statusFor(gapCm: number): WaterStatus {
  if (gapCm <= WATCH_GAP_CM) { return 'alert'; }
  if (gapCm < CALM_GAP_CM) { return 'watch'; }
  return 'calm';
}

/** «7,5 м» для дальних расстояний и «40 см» для близких. */
export function formatDistance(cm: number): string {
  if (cm < 100) { return `${Math.round(cm)} см`; }
  return formatMeters(cm / 100, 1);
}

/** Метры с русской запятой: 35.16 → «35,16 м». */
export function formatMeters(m: number, decimals: number): string {
  const s = m.toFixed(decimals).replace('.', ',');
  // Разделитель тысяч — неразрывный пробел, как number_format(..., ' ').
  const [int, frac] = s.split(',');
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, '\u2009');
  return (frac ? `${grouped},${frac}` : grouped) + ' м';
}

/** «поднялась на 12 см за сутки» / «опустилась на 8 см» / «без изменений». */
export function changeLabel(changeCm: number): string {
  const abs = Math.round(Math.abs(changeCm));
  if (abs < 1) { return 'за сутки без изменений'; }
  const verb = changeCm > 0 ? 'поднялась' : 'опустилась';
  return `за сутки ${verb} на ${abs} см`;
}

/** 'YYYY-MM-DD' → '29 авг'. */
function shortDate(date: string): string {
  const months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
  if (!m) { return date; }
  return `${parseInt(m[3], 10)} ${months[parseInt(m[2], 10) - 1]}`;
}

/**
 * 'Y-m-d H:i:s' по UTC (так хранятся замеры) → 'HH:MM' по Москве. Метку читаем
 * явно как UTC: пояс приложения — Москва, и без этого время замера показалось
 * бы на три часа раньше настоящего.
 */
function mskTime(stamp: string): string {
  const d = new Date(/Z|[+-]\d{2}:?\d{2}$/.test(stamp) ? stamp : stamp + 'Z');
  if (Number.isNaN(d.getTime())) { return stamp.slice(11, 16); }
  return d.toLocaleTimeString('ru-RU', {
    hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Europe/Moscow',
  });
}

/**
 * Геометрия диаграммы в координатах viewBox (0..w, 0..h): ломаная уровня,
 * заливка под ней и подписи краёв. null — точек слишком мало для линии.
 * Отметки моста и опасного уровня попадают в результат только когда входят
 * в диапазон данных: обычно вода много ниже моста, и линия ушла бы за рамку.
 */
export function buildChart(points: ChartPoint[], w = 300, h = 110): ChartGeometry | null {
  if (points.length < 2) { return null; }

  const levels = points.map((p) => p.levelCm);
  let min = Math.min(...levels);
  let max = Math.max(...levels);
  if (max - min < 1) { min -= 1; max += 1; }   // штиль — ломаная по центру

  const pad = 8;
  const y = (cm: number): number => {
    const k = (cm - min) / (max - min);
    return Math.round((h - pad - k * (h - 2 * pad)) * 10) / 10;
  };
  const x = (i: number): number => Math.round((i * w / (points.length - 1)) * 10) / 10;

  const coords: string[] = [];
  for (let i = 0; i < points.length; i++) {
    coords.push(`${x(i)},${y(points[i].levelCm)}`);
  }

  const marks: Array<{ y: number; label: string }> = [];
  for (const m of [{ cm: BRIDGE_CM, label: 'кромка моста' }, { cm: HIGH_FLOOD_CM, label: 'опасный уровень' }]) {
    if (m.cm >= min && m.cm <= max) { marks.push({ y: y(m.cm), label: m.label }); }
  }

  return {
    w,
    h,
    poly: coords.join(' '),
    area: `0,${h} ${coords.join(' ')} ${w},${h}`,
    // Одна подпись на весь размах: «27,16 – 27,67 м БСВ».
    rangeLabel: `${toBsv(min).toFixed(2).replace('.', ',')} – ${formatMeters(toBsv(max), 2)} БСВ`,
    firstDate: shortDate(points[0].date),
    lastDate: shortDate(points[points.length - 1].date),
    days: points.length,
    marks,
  };
}

/**
 * Спросить текущий замер у источника, ничего не записывая. Таймаут короткий:
 * панель открывается по клику, и ждать чужой Vercel дольше пары секунд нельзя.
 */
export async function probeSource(timeoutMs = 4000): Promise<{ levelCm: number; change24h: number } | null> {
  const url = process.env.WATER_LEVEL_API
    || 'https://shebsh-water-level.vercel.app/api/water-level';
  try {
    const r = await fetch(url, { signal: AbortSignal.timeout(timeoutMs) });
    if (!r.ok) {
      console.error(`WaterLevel: источник ответил ${r.status}`);
      return null;
    }
    const data = await r.json() as { water_level?: unknown; change_24h?: unknown };
    if (!data || typeof data.water_level !== 'number' || !Number.isFinite(data.water_level)) {
      console.error('WaterLevel: неожиданный ответ источника');
      return null;
    }
    return {
      levelCm: data.water_level,
      change24h: typeof data.change_24h === 'number' && Number.isFinite(data.change_24h) ? data.change_24h : 0,
    };
  } catch (e) {
    console.error('WaterLevel: ошибка запроса источника:', (e as Error).message);
    return null;
  }
}

/** Записать замер в историю. PK — само время, повтор не плодит строк. */
export async function saveSnapshot(measuredAt: Date, levelCm: number, change24h: number): Promise<void> {
  await db.waterLevelHistory.upsert({
    where: { measuredAt },
    create: { measuredAt, levelCm, change24h },
    update: { levelCm, change24h },
  });
}

/**
 * Спросить источник и записать замер в историю. Возвращает записанные данные
 * или null, если источник недоступен (сеть, 502 у Vercel, мусор в ответе).
 */
export async function snapshot(now: Date): Promise<{ levelCm: number; change24h: number } | null> {
  const data = await probeSource();
  if (data === null) { return null; }
  await saveSnapshot(now, data.levelCm, data.change24h);
  return data;
}

/** Точки по одной на сутки за CHART_DAYS дней: максимум за сутки, новые сверху → разворот. */
async function dailyPoints(now: Date): Promise<ChartPoint[]> {
  const since = new Date(now.getTime() - CHART_DAYS * 86400000);
  const rows = await db.waterLevelHistory.findMany({
    where: { measuredAt: { gte: since } },
    orderBy: { measuredAt: 'desc' },
    select: { measuredAt: true, levelCm: true, change24h: true },
  });
  // По суткам берём последний замер дня (rows отсортированы DESC — первый и есть).
  const byDay = new Map<string, ChartPoint>();
  for (const r of rows) {
    const date = r.measuredAt.toISOString().slice(0, 10);
    if (byDay.has(date)) { continue; }
    byDay.set(date, { date, levelCm: Number(r.levelCm), change24h: Number(r.change24h) });
  }
  return [...byDay.values()].sort((a, b) => a.date.localeCompare(b.date));
}

/**
 * Данные панели: свежий замер (при надобности спросив источник), запас до
 * моста, подписи и геометрия диаграммы. level === null — показать «нет данных».
 */
export async function panel(now: Date = new Date()): Promise<WaterPanel> {
  let row = await db.waterLevelHistory.findFirst({ orderBy: { measuredAt: 'desc' } });

  const stale = !row || now.getTime() - row.measuredAt.getTime() > STALE_MINUTES * 60000;
  if (stale) {
    const fresh = await snapshot(now);
    if (fresh !== null) {
      row = await db.waterLevelHistory.findFirst({ orderBy: { measuredAt: 'desc' } });
    }
  }

  if (!row) { return { level: null, chart: null }; }

  const level = Number(row.levelCm);
  const change = Number(row.change24h);
  const gap = bridgeGapCm(level);

  return {
    level,
    levelBsv: toBsv(level),
    levelLabel: formatMeters(toBsv(level), 2),
    gap,
    gapLabel: formatDistance(Math.abs(gap)),
    flooded: gap <= 0,
    status: statusFor(gap),
    change,
    changeLabel: changeLabel(change),
    measuredAt: mskTime(row.measuredAt.toISOString().replace('T', ' ').slice(0, 19)),
    chart: buildChart(await dailyPoints(now)),
  };
}
