# Обучающий ролик про приложение жителей — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Собрать минутный вертикальный ролик 1080×1920 о приложении жителей и отладить на нём оснастку, чтобы следующие ролики по разделам собирались из тех же компонентов.

**Architecture:** Отдельный Remotion-проект `C:\Projects\skaz-video`, вне репозитория сайта. Конкретный ролик описан данными в `scenario.ts`; компоненты сцен (`SceneCard`, `SceneFull`, `SceneZoom`) рендерят кадр по этим данным. Геометрия камеры вынесена в чистые функции `camera.ts` и покрыта тестами; размеры скриншотов не хардкодятся, а сканируются из самих PNG в `src/shots.ts`.

**Tech Stack:** Remotion (React + TypeScript), vitest для тестов, шрифты PT Serif / PT Sans копией из `residents/public/assets/fonts/`.

**Спека:** `docs/superpowers/specs/2026-09-23-obuchayushchiy-rolik-app-design.md`

---

## Как считается длительность

Кроссфейд забирает 12 кадров на каждом стыке. Чтобы экранное время сцен совпало
со спекой, `frames` в сценарии включает входящий переход:

```
длина композиции = сумма(frames) − 12 × (сцен − 1) = 1896 − 96 = 1800
```

Первая сцена входящего перехода не имеет, поэтому её 120 кадров идут как есть.
Остальные восемь получают по +12 к значению из спеки.

## Что делает человек, а не агент

Скриншоты снимает заказчик своим Android — их нельзя получить кодом. Задачи 1–10
выполняются без кадров, на синтетической заглушке. Задачи 11–12 требуют реальных
восьми PNG.

## Структура файлов

| Файл | Ответственность |
|------|-----------------|
| `src/config.ts` | Константы кадра: размер, fps, длина кроссфейда |
| `src/types.ts` | Типы `Rect`, `Size`, `Scene` |
| `src/camera.ts` | Чистая геометрия: проезд и наезд |
| `src/camera.test.ts` | Тесты геометрии |
| `src/validate.ts` | Чистые проверки сценария |
| `src/validate.test.ts` | Тесты проверок |
| `src/check.test.ts` | Проверка сценария против реальных файлов |
| `src/shots.ts` | Генерируется скриптом: размеры кадров |
| `scripts/scan-shots.mjs` | Читает PNG, пишет `src/shots.ts` |
| `src/ui/theme.ts` | Цвета и шрифты раздела |
| `src/ui/fonts.ts` | Загрузка локальных шрифтов |
| `src/ui/Title.tsx` | Титр с плашкой и безопасной зоной |
| `src/scenes/SceneCard.tsx` | Заставка и финал |
| `src/scenes/SceneFull.tsx` | Экран во весь кадр с проездом |
| `src/scenes/SceneZoom.tsx` | Наезд на область (один или два кадра) |
| `src/scenario.ts` | Девять сцен ролика |
| `src/Video.tsx` | Раскладка сцен по времени и кроссфейды |
| `src/Grid.tsx` | Сетка координат для разметки наездов |
| `src/Root.tsx` | Регистрация композиций |

---

### Task 1: Каркас проекта

**Files:**
- Create: `C:\Projects\skaz-video\` (весь проект)
- Create: `C:\Projects\skaz-video\.gitignore`

- [ ] **Step 1: Создать проект из пустого шаблона**

```bash
cd /c/Projects
npx create-video@latest --blank skaz-video
```

Ожидается: появилась папка `skaz-video` с `package.json`, `remotion.config.ts`,
`src/Root.tsx`, `src/index.ts`.

- [ ] **Step 2: Поставить зависимости и убедиться, что Studio открывается**

```bash
cd /c/Projects/skaz-video
npm install
npm run dev
```

Ожидается: открывается Remotion Studio в браузере с демо-композицией. Закрыть
по Ctrl+C после проверки.

- [ ] **Step 3: Поставить скиллы Remotion в проект**

Плагин Claude Code с теми же скиллами на машине не установился (проверено
`claude plugin list` 2026-09-23), поэтому берём их проектной командой:

```bash
cd /c/Projects/skaz-video
npx remotion skills add
```

Ожидается: в проекте появилась папка со скиллами Remotion. Перезапустить
Claude Code, чтобы он их подхватил.

- [ ] **Step 4: Добавить vitest**

```bash
npm install -D vitest
```

- [ ] **Step 5: Прописать команды в `package.json`**

В разделе `"scripts"` должно быть ровно это (остальные строки шаблона не трогать):

```json
"scripts": {
  "dev": "remotion studio",
  "check": "vitest run",
  "scan": "node scripts/scan-shots.mjs",
  "render": "remotion render Obzor out/obzor.mp4 --crf 18"
}
```

- [ ] **Step 6: Записать `.gitignore`**

```
node_modules/
out/
.remotion/
```

Папка `public/shots/` в git входит — кадры должны пережить пересъёмку интерфейса.

- [ ] **Step 7: Первый коммит**

```bash
cd /c/Projects/skaz-video
git init
git add -A
git commit -m "Каркас проекта Remotion"
```

---

### Task 2: Константы и типы

**Files:**
- Create: `src/config.ts`
- Create: `src/types.ts`

- [ ] **Step 1: Написать `src/config.ts`**

```ts
/** Кадр ролика: вертикаль под телефон. */
export const CANVAS = { width: 1080, height: 1920 } as const;

export const FPS = 30;

/** Длина кроссфейда между сценами, кадров. */
export const CROSSFADE = 12;

/** Итоговая длина ролика, кадров (60 секунд). */
export const TOTAL_FRAMES = 1800;

/** Отступ вокруг области наезда, пикселей скриншота. */
export const ZOOM_PADDING = 40;
```

- [ ] **Step 2: Написать `src/types.ts`**

```ts
export type Size = { width: number; height: number };

/** Прямоугольник в координатах скриншота, пиксели. */
export type Rect = { x: number; y: number; w: number; h: number };

export type Scene =
  | { kind: 'card'; title: string; note?: string; frames: number }
  | { kind: 'full'; shot: string; title: string; frames: number }
  | { kind: 'zoom'; shot: string; title: string; frames: number; focus: Rect }
  | {
      kind: 'zoom2';
      shots: [string, string];
      title: string;
      frames: number;
      focus: [Rect, Rect];
    };

/** Имена кадров, на которые ссылается сцена. Пусто для рисованных сцен. */
export const shotsOf = (scene: Scene): string[] => {
  if (scene.kind === 'card') return [];
  if (scene.kind === 'zoom2') return [...scene.shots];
  return [scene.shot];
};
```

- [ ] **Step 3: Коммит**

```bash
git add src/config.ts src/types.ts
git commit -m "Типы сцен и константы кадра"
```

---

### Task 3: Геометрия камеры

Две функции, где ошибка не видна глазом до рендера: вертикальный проезд и наезд.

**Files:**
- Create: `src/camera.ts`
- Test: `src/camera.test.ts`

- [ ] **Step 1: Написать падающие тесты**

`src/camera.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { panOffset, zoomTransform } from './camera';
import { CANVAS } from './config';

const SHOT = { width: 1080, height: 2400 };

describe('panOffset', () => {
  it('в начале сцены показывает шапку', () => {
    expect(panOffset(SHOT, CANVAS, 0)).toBe(0);
  });

  it('в конце доезжает ровно до нижней кромки', () => {
    expect(panOffset(SHOT, CANVAS, 1)).toBe(-(2400 - 1920));
  });

  it('не уезжает в пустоту, если скриншот короче кадра', () => {
    expect(panOffset({ width: 1080, height: 1600 }, CANVAS, 1)).toBe(0);
  });

  it('зажимает прогресс за пределами [0,1]', () => {
    expect(panOffset(SHOT, CANVAS, 1.5)).toBe(-(2400 - 1920));
    expect(panOffset(SHOT, CANVAS, -0.5)).toBe(0);
  });
});

describe('zoomTransform', () => {
  const focus = { x: 100, y: 800, w: 400, h: 300 };

  it('приближает область, а не отдаляет', () => {
    const { scale } = zoomTransform(SHOT, CANVAS, focus);
    expect(scale).toBeGreaterThanOrEqual(CANVAS.width / SHOT.width);
  });

  it('не показывает пустоту за краями изображения', () => {
    const { scale, x, y } = zoomTransform(SHOT, CANVAS, focus);
    expect(x).toBeLessThanOrEqual(0);
    expect(y).toBeLessThanOrEqual(0);
    expect(x).toBeGreaterThanOrEqual(CANVAS.width - SHOT.width * scale);
    expect(y).toBeGreaterThanOrEqual(CANVAS.height - SHOT.height * scale);
  });

  it('держит область наезда внутри кадра', () => {
    const { scale, x, y } = zoomTransform(SHOT, CANVAS, focus);
    const left = focus.x * scale + x;
    const top = focus.y * scale + y;
    expect(left).toBeGreaterThanOrEqual(0);
    expect(top).toBeGreaterThanOrEqual(0);
    expect(left + focus.w * scale).toBeLessThanOrEqual(CANVAS.width);
    expect(top + focus.h * scale).toBeLessThanOrEqual(CANVAS.height);
  });
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

```bash
npx vitest run src/camera.test.ts
```

Ожидается: FAIL — `Failed to resolve import "./camera"`.

- [ ] **Step 3: Написать `src/camera.ts`**

```ts
import { ZOOM_PADDING } from './config';
import type { Rect, Size } from './types';

const clamp = (v: number, min: number, max: number) =>
  Math.min(Math.max(v, min), max);

/**
 * Вертикальный проезд по скриншоту, вписанному по ширине кадра.
 * Возвращает смещение по Y в пикселях кадра: 0 — шапка, отрицательное — ниже.
 */
export const panOffset = (shot: Size, canvas: Size, progress: number): number => {
  const scale = canvas.width / shot.width;
  const scaledHeight = shot.height * scale;
  const travel = Math.max(0, scaledHeight - canvas.height);
  return -travel * clamp(progress, 0, 1);
};

/**
 * Наезд на область: масштаб и смещение, при которых `focus` занимает кадр
 * с отступом, а за краями изображения не появляется пустота.
 */
export const zoomTransform = (
  shot: Size,
  canvas: Size,
  focus: Rect,
  padding: number = ZOOM_PADDING,
): { scale: number; x: number; y: number } => {
  const base = canvas.width / shot.width;
  const fit = Math.min(
    canvas.width / (focus.w + padding * 2),
    canvas.height / (focus.h + padding * 2),
  );
  const scale = Math.max(base, fit);

  const centerX = (focus.x + focus.w / 2) * scale;
  const centerY = (focus.y + focus.h / 2) * scale;

  const x = clamp(canvas.width / 2 - centerX, canvas.width - shot.width * scale, 0);
  const y = clamp(canvas.height / 2 - centerY, canvas.height - shot.height * scale, 0);

  return { scale, x, y };
};
```

- [ ] **Step 4: Убедиться, что тесты проходят**

```bash
npx vitest run src/camera.test.ts
```

Ожидается: PASS, 7 тестов.

- [ ] **Step 5: Коммит**

```bash
git add src/camera.ts src/camera.test.ts
git commit -m "Геометрия камеры: проезд и наезд"
```

---

### Task 4: Проверки сценария

**Files:**
- Create: `src/validate.ts`
- Test: `src/validate.test.ts`

- [ ] **Step 1: Написать падающие тесты**

`src/validate.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { missingShots, timelineLength } from './validate';
import type { Scene } from './types';

const card = (frames: number): Scene => ({ kind: 'card', title: 'x', frames });
const full = (shot: string, frames: number): Scene => ({
  kind: 'full',
  shot,
  title: 'x',
  frames,
});

describe('timelineLength', () => {
  it('вычитает кроссфейд на каждом стыке', () => {
    expect(timelineLength([card(120), full('a.png', 222)])).toBe(330);
  });

  it('одна сцена идёт без вычета', () => {
    expect(timelineLength([card(120)])).toBe(120);
  });

  it('пустой сценарий даёт ноль', () => {
    expect(timelineLength([])).toBe(0);
  });
});

describe('missingShots', () => {
  it('находит кадр, которого нет на диске', () => {
    const scenes = [full('a.png', 100), full('b.png', 100)];
    expect(missingShots(scenes, ['a.png'])).toEqual(['b.png']);
  });

  it('молчит, когда все кадры на месте', () => {
    expect(missingShots([full('a.png', 100)], ['a.png', 'lishniy.png'])).toEqual([]);
  });

  it('не спотыкается о рисованные сцены', () => {
    expect(missingShots([card(120)], [])).toEqual([]);
  });
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

```bash
npx vitest run src/validate.test.ts
```

Ожидается: FAIL — `Failed to resolve import "./validate"`.

- [ ] **Step 3: Написать `src/validate.ts`**

```ts
import { CROSSFADE } from './config';
import { shotsOf, type Scene } from './types';

/** Длина ролика с учётом перекрытий на кроссфейдах. */
export const timelineLength = (scenes: Scene[]): number => {
  if (scenes.length === 0) return 0;
  const sum = scenes.reduce((acc, s) => acc + s.frames, 0);
  return sum - CROSSFADE * (scenes.length - 1);
};

/** Кадры, на которые ссылается сценарий, но которых нет среди файлов. */
export const missingShots = (scenes: Scene[], available: string[]): string[] => {
  const have = new Set(available);
  const need = new Set(scenes.flatMap(shotsOf));
  return [...need].filter((name) => !have.has(name));
};
```

- [ ] **Step 4: Убедиться, что тесты проходят**

```bash
npx vitest run src/validate.test.ts
```

Ожидается: PASS, 6 тестов.

- [ ] **Step 5: Коммит**

```bash
git add src/validate.ts src/validate.test.ts
git commit -m "Проверки сценария: длина и наличие кадров"
```

---

### Task 5: Сканер размеров кадров

Размеры скриншотов не хардкодятся: телефоны снимают по-разному. Скрипт читает
заголовок каждого PNG и пишет `src/shots.ts`.

**Files:**
- Create: `scripts/scan-shots.mjs`
- Create: `public/shots/.gitkeep`

- [ ] **Step 1: Создать папку для кадров**

```bash
mkdir -p /c/Projects/skaz-video/public/shots
touch /c/Projects/skaz-video/public/shots/.gitkeep
```

- [ ] **Step 2: Написать `scripts/scan-shots.mjs`**

Размеры лежат в блоке IHDR: байты 16–19 — ширина, 20–23 — высота, big-endian.

```js
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const SHOTS_DIR = 'public/shots';
const OUT = 'src/shots.ts';

const pngSize = (path) => {
  const buf = readFileSync(path);
  const isPng = buf.subarray(0, 8).equals(
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  );
  if (!isPng) throw new Error(`${path}: это не PNG`);
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
};

const files = readdirSync(SHOTS_DIR).filter((f) => f.endsWith('.png')).sort();
const entries = files.map((f) => [f, pngSize(join(SHOTS_DIR, f))]);

const body = entries
  .map(([name, s]) => `  '${name}': { width: ${s.width}, height: ${s.height} },`)
  .join('\n');

writeFileSync(
  OUT,
  `// Генерируется скриптом scripts/scan-shots.mjs — руками не править.\n` +
    `import type { Size } from './types';\n\n` +
    `export const SHOTS: Record<string, Size> = {\n${body}\n};\n`,
  'utf8',
);

console.log(`Кадров найдено: ${files.length}`);
for (const [name, s] of entries) console.log(`  ${name} — ${s.width}×${s.height}`);
```

- [ ] **Step 3: Проверить на заглушке**

Пока настоящих кадров нет, положить в `public/shots/` любой PNG под именем
`03-glavnyy.png` (подойдёт скриншот чего угодно) и запустить:

```bash
cd /c/Projects/skaz-video
npm run scan
```

Ожидается: вывод `Кадров найдено: 1` и строка с размерами; создан `src/shots.ts`.

- [ ] **Step 4: Коммит**

```bash
git add scripts/scan-shots.mjs public/shots/.gitkeep src/shots.ts
git commit -m "Сканер размеров кадров"
```

---

### Task 6: Шрифты и тема

**Files:**
- Create: `src/ui/theme.ts`
- Create: `src/ui/fonts.ts`
- Create: `public/fonts/` (четыре woff2)

- [ ] **Step 1: Скопировать шрифты из репозитория портала**

```bash
mkdir -p /c/Projects/skaz-video/public/fonts
cp /c/Projects/skaz-kray-astro/residents/public/assets/fonts/pt-serif-cyrillic-400-normal.woff2 \
   /c/Projects/skaz-kray-astro/residents/public/assets/fonts/pt-serif-cyrillic-700-normal.woff2 \
   /c/Projects/skaz-kray-astro/residents/public/assets/fonts/pt-sans-cyrillic-400-normal.woff2 \
   /c/Projects/skaz-kray-astro/residents/public/assets/fonts/pt-sans-cyrillic-700-normal.woff2 \
   /c/Projects/skaz-video/public/fonts/
```

- [ ] **Step 2: Поставить пакет шрифтов Remotion**

```bash
cd /c/Projects/skaz-video
npm install @remotion/fonts
```

- [ ] **Step 3: Написать `src/ui/theme.ts`**

Цвета взяты из `residents/public/assets/residents.css`.

```ts
export const theme = {
  green: '#008757',
  ochre: '#c98a3a',
  ink: '#2b2b2b',
  paper: '#fbfaf6',
  muted: '#6b6b6b',
  head: '"PT Serif", Georgia, serif',
  body: '"PT Sans", sans-serif',
} as const;

/** Нижняя зона кадра, перекрываемая элементами плеера Telegram. */
export const SAFE_BOTTOM = 180;
```

- [ ] **Step 4: Написать `src/ui/fonts.ts`**

```ts
import { loadFont } from '@remotion/fonts';
import { staticFile } from 'remotion';

export const loadFonts = () => {
  loadFont({
    family: 'PT Serif',
    url: staticFile('fonts/pt-serif-cyrillic-400-normal.woff2'),
    weight: '400',
  });
  loadFont({
    family: 'PT Serif',
    url: staticFile('fonts/pt-serif-cyrillic-700-normal.woff2'),
    weight: '700',
  });
  loadFont({
    family: 'PT Sans',
    url: staticFile('fonts/pt-sans-cyrillic-400-normal.woff2'),
    weight: '400',
  });
  loadFont({
    family: 'PT Sans',
    url: staticFile('fonts/pt-sans-cyrillic-700-normal.woff2'),
    weight: '700',
  });
};
```

- [ ] **Step 5: Коммит**

```bash
git add public/fonts src/ui/theme.ts src/ui/fonts.ts package.json package-lock.json
git commit -m "Фирменные шрифты и цвета"
```

---

### Task 7: Титр

**Files:**
- Create: `src/ui/Title.tsx`

- [ ] **Step 1: Написать `src/ui/Title.tsx`**

```tsx
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { SAFE_BOTTOM, theme } from './theme';

export const Title: React.FC<{ text: string }> = ({ text }) => {
  const frame = useCurrentFrame();
  const appear = interpolate(frame, [6, 20], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const rise = interpolate(appear, [0, 1], [24, 0]);

  return (
    <AbsoluteFill style={{ justifyContent: 'flex-end' }}>
      <div
        style={{
          paddingTop: 220,
          paddingBottom: SAFE_BOTTOM,
          paddingLeft: 72,
          paddingRight: 72,
          background:
            'linear-gradient(transparent, rgba(43,43,43,0.88) 42%, rgba(43,43,43,0.94))',
        }}
      >
        <div
          style={{
            opacity: appear,
            transform: `translateY(${rise}px)`,
            fontFamily: theme.head,
            fontWeight: 700,
            fontSize: 62,
            lineHeight: 1.22,
            color: theme.paper,
            textAlign: 'center',
          }}
        >
          {text}
        </div>
      </div>
    </AbsoluteFill>
  );
};
```

- [ ] **Step 2: Коммит**

```bash
git add src/ui/Title.tsx
git commit -m "Титр с плашкой и безопасной зоной"
```

---

### Task 8: Рисованные сцены — заставка и финал

**Files:**
- Create: `src/scenes/SceneCard.tsx`

- [ ] **Step 1: Написать `src/scenes/SceneCard.tsx`**

```tsx
import { AbsoluteFill, interpolate, useCurrentFrame } from 'remotion';
import { theme } from '../ui/theme';

export const SceneCard: React.FC<{ title: string; note?: string }> = ({
  title,
  note,
}) => {
  const frame = useCurrentFrame();
  const appear = interpolate(frame, [4, 22], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const scale = interpolate(appear, [0, 1], [0.96, 1]);

  return (
    <AbsoluteFill
      style={{
        backgroundColor: theme.green,
        justifyContent: 'center',
        alignItems: 'center',
        padding: 96,
      }}
    >
      <div style={{ opacity: appear, transform: `scale(${scale})`, textAlign: 'center' }}>
        <div
          style={{
            fontFamily: theme.head,
            fontWeight: 700,
            fontSize: 92,
            lineHeight: 1.18,
            color: theme.paper,
          }}
        >
          {title}
        </div>
        {note ? (
          <div
            style={{
              marginTop: 36,
              fontFamily: theme.body,
              fontSize: 46,
              color: theme.paper,
              opacity: 0.85,
            }}
          >
            {note}
          </div>
        ) : null}
      </div>
    </AbsoluteFill>
  );
};
```

- [ ] **Step 2: Коммит**

```bash
git add src/scenes/SceneCard.tsx
git commit -m "Сцена-карточка: заставка и финал"
```

---

### Task 9: Сцена «экран во весь кадр»

**Files:**
- Create: `src/scenes/SceneFull.tsx`

- [ ] **Step 1: Написать `src/scenes/SceneFull.tsx`**

```tsx
import {
  AbsoluteFill,
  Easing,
  Img,
  interpolate,
  staticFile,
  useCurrentFrame,
} from 'remotion';
import { panOffset } from '../camera';
import { CANVAS } from '../config';
import { SHOTS } from '../shots';
import { Title } from '../ui/Title';
import { theme } from '../ui/theme';

/**
 * `frames` приходит пропом, а не из useVideoConfig: внутри Sequence хук
 * возвращает длину всей композиции, а нам нужна длина своей сцены.
 */
export const SceneFull: React.FC<{ shot: string; title: string; frames: number }> = ({
  shot,
  title,
  frames: duration,
}) => {
  const frame = useCurrentFrame();
  const size = SHOTS[shot];

  if (!size) throw new Error(`Нет размеров кадра ${shot}: запустите npm run scan`);

  const progress = interpolate(frame, [0, duration], [0, 1], {
    extrapolateRight: 'clamp',
    easing: Easing.inOut(Easing.ease),
  });
  const offset = panOffset(size, CANVAS, progress);

  return (
    <AbsoluteFill style={{ backgroundColor: theme.ink }}>
      <Img
        src={staticFile(`shots/${shot}`)}
        style={{
          width: CANVAS.width,
          height: (size.height * CANVAS.width) / size.width,
          transform: `translateY(${offset}px)`,
        }}
      />
      <Title text={title} />
    </AbsoluteFill>
  );
};
```

- [ ] **Step 2: Коммит**

```bash
git add src/scenes/SceneFull.tsx
git commit -m "Сцена с вертикальным проездом по экрану"
```

---

### Task 10: Сцена наезда

Один компонент обслуживает и `zoom`, и `zoom2`: второй просто показывает два
кадра подряд, деля своё время пополам.

**Files:**
- Create: `src/scenes/SceneZoom.tsx`

- [ ] **Step 1: Написать `src/scenes/SceneZoom.tsx`**

```tsx
import {
  AbsoluteFill,
  Img,
  interpolate,
  Sequence,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import { panOffset, zoomTransform } from '../camera';
import { CANVAS } from '../config';
import { SHOTS } from '../shots';
import type { Rect } from '../types';
import { Title } from '../ui/Title';
import { theme } from '../ui/theme';

const ZoomShot: React.FC<{ shot: string; focus: Rect; frames: number }> = ({
  shot,
  focus,
  frames: duration,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const size = SHOTS[shot];

  if (!size) throw new Error(`Нет размеров кадра ${shot}: запустите npm run scan`);

  const progress = spring({
    frame,
    fps,
    config: { damping: 200 },
    durationInFrames: duration,
  });
  const target = zoomTransform(size, CANVAS, focus);
  const base = {
    scale: CANVAS.width / size.width,
    x: 0,
    y: panOffset(size, CANVAS, 0),
  };

  const scale = interpolate(progress, [0, 1], [base.scale, target.scale]);
  const x = interpolate(progress, [0, 1], [base.x, target.x]);
  const y = interpolate(progress, [0, 1], [base.y, target.y]);
  const dim = interpolate(progress, [0, 1], [0, 0.45]);

  const left = focus.x * scale + x;
  const top = focus.y * scale + y;

  return (
    <AbsoluteFill style={{ backgroundColor: theme.ink, overflow: 'hidden' }}>
      <Img
        src={staticFile(`shots/${shot}`)}
        style={{
          position: 'absolute',
          left: 0,
          top: 0,
          width: size.width,
          height: size.height,
          transformOrigin: 'top left',
          transform: `translate(${x}px, ${y}px) scale(${scale})`,
        }}
      />
      <AbsoluteFill
        style={{
          boxShadow: `0 0 0 9999px rgba(43,43,43,${dim})`,
          left,
          top,
          width: focus.w * scale,
          height: focus.h * scale,
          border: `4px solid rgba(201,138,58,${dim * 1.8})`,
          borderRadius: 18,
        }}
      />
    </AbsoluteFill>
  );
};

export const SceneZoom: React.FC<{
  shots: string[];
  focus: Rect[];
  title: string;
  frames: number;
}> = ({ shots, focus, title, frames: duration }) => {
  const part = Math.floor(duration / shots.length);

  return (
    <AbsoluteFill>
      {shots.map((shot, i) => {
        const own = i === shots.length - 1 ? duration - i * part : part;
        return (
          <Sequence key={shot} from={i * part} durationInFrames={own}>
            <ZoomShot shot={shot} focus={focus[i]} frames={own} />
          </Sequence>
        );
      })}
      <Title text={title} />
    </AbsoluteFill>
  );
};
```

- [ ] **Step 2: Коммит**

```bash
git add src/scenes/SceneZoom.tsx
git commit -m "Сцена наезда на область экрана"
```

---

### Task 11: Сценарий ролика

Координаты наездов пока заданы как весь экран — Task 13 заменит их точными
после разметки по сетке. Ролик при этом уже собирается и играет.

**Files:**
- Create: `src/scenario.ts`

- [ ] **Step 1: Написать `src/scenario.ts`**

```ts
import type { Rect, Scene } from './types';

/** Заглушка до разметки по сетке (Task 13): наезд на весь экран. */
const WHOLE: Rect = { x: 0, y: 0, w: 1080, h: 2400 };

/**
 * Девять сцен. `frames` включает входящий кроссфейд (12 кадров),
 * поэтому у всех сцен, кроме первой, значение на 12 больше экранного времени.
 * Сумма 1896 − 8×12 = 1800 кадров = 60 секунд.
 */
export const scenario: Scene[] = [
  { kind: 'card', title: 'Что уже умеет наше приложение', frames: 120 },
  {
    kind: 'zoom2',
    shots: ['01-zakrep.png', '02-ssylka.png'],
    focus: [WHOLE, WHOLE],
    title: 'Ссылка — в закрепе группы',
    frames: 222,
  },
  {
    kind: 'full',
    shot: '03-glavnyy.png',
    title: 'Дневник поместья и новости соседей',
    frames: 282,
  },
  {
    kind: 'zoom',
    shot: '04-yarmarka.png',
    focus: WHOLE,
    title: 'Ярмарка — что продают соседи',
    frames: 222,
  },
  {
    kind: 'zoom',
    shot: '05-instrumenty.png',
    focus: WHOLE,
    title: 'Книги и инструменты — у кого что взять',
    frames: 222,
  },
  {
    kind: 'zoom',
    shot: '06-poezdki.png',
    focus: WHOLE,
    title: 'Поездки — кто и когда едет в город',
    frames: 222,
  },
  {
    kind: 'zoom',
    shot: '07-obshchiy-dom.png',
    focus: WHOLE,
    title: 'Общий дом — бронь помещений и протоколы',
    frames: 222,
  },
  {
    kind: 'zoom',
    shot: '08-sosedi.png',
    focus: WHOLE,
    title: 'Соседи — кто где живёт',
    frames: 222,
  },
  {
    kind: 'card',
    title: 'Откройте — ссылка в закрепе',
    note: 'группа жителей в Telegram',
    frames: 162,
  },
];
```

- [ ] **Step 2: Написать проверку сценария против реальных файлов**

`src/check.test.ts`:

```ts
import { readdirSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { scenario } from './scenario';
import { missingShots, timelineLength } from './validate';
import { TOTAL_FRAMES } from './config';

describe('сценарий ролика', () => {
  it('складывается ровно в объявленную длину', () => {
    expect(timelineLength(scenario)).toBe(TOTAL_FRAMES);
  });

  it('ссылается только на существующие кадры', () => {
    const files = readdirSync('public/shots').filter((f) => f.endsWith('.png'));
    expect(missingShots(scenario, files)).toEqual([]);
  });
});
```

- [ ] **Step 3: Запустить проверку**

```bash
cd /c/Projects/skaz-video
npm run check
```

Ожидается: тест длины — PASS. Тест кадров — FAIL со списком недостающих файлов,
пока заказчик не прислал скриншоты. Это правильное поведение проверки.

- [ ] **Step 4: Коммит**

```bash
git add src/scenario.ts src/check.test.ts
git commit -m "Сценарий ролика и проверка против файлов"
```

---

### Task 12: Сборка ролика и композиции

**Files:**
- Create: `src/Video.tsx`
- Create: `src/Grid.tsx`
- Modify: `src/Root.tsx` (заменить содержимое целиком)

- [ ] **Step 1: Написать `src/Video.tsx`**

```tsx
import { AbsoluteFill, interpolate, Sequence, useCurrentFrame } from 'remotion';
import { CROSSFADE } from './config';
import { scenario } from './scenario';
import { SceneCard } from './scenes/SceneCard';
import { SceneFull } from './scenes/SceneFull';
import { SceneZoom } from './scenes/SceneZoom';
import type { Scene } from './types';

const Body: React.FC<{ scene: Scene }> = ({ scene }) => {
  switch (scene.kind) {
    case 'card':
      return <SceneCard title={scene.title} note={scene.note} />;
    case 'full':
      return <SceneFull shot={scene.shot} title={scene.title} frames={scene.frames} />;
    case 'zoom':
      return (
        <SceneZoom
          shots={[scene.shot]}
          focus={[scene.focus]}
          title={scene.title}
          frames={scene.frames}
        />
      );
    case 'zoom2':
      return (
        <SceneZoom
          shots={scene.shots}
          focus={scene.focus}
          title={scene.title}
          frames={scene.frames}
        />
      );
  }
};

/** Наплыв: первые CROSSFADE кадров сцена проявляется поверх предыдущей. */
const FadeIn: React.FC<{ first: boolean; children: React.ReactNode }> = ({
  first,
  children,
}) => {
  const frame = useCurrentFrame();
  const opacity = first
    ? 1
    : interpolate(frame, [0, CROSSFADE], [0, 1], { extrapolateRight: 'clamp' });
  return <AbsoluteFill style={{ opacity }}>{children}</AbsoluteFill>;
};

export const Video: React.FC = () => {
  let from = 0;

  return (
    <AbsoluteFill>
      {scenario.map((scene, i) => {
        const start = from;
        from += scene.frames - CROSSFADE;
        return (
          <Sequence key={i} from={start} durationInFrames={scene.frames}>
            <FadeIn first={i === 0}>
              <Body scene={scene} />
            </FadeIn>
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};
```

- [ ] **Step 2: Написать `src/Grid.tsx`**

Вспомогательная композиция для снятия координат наездов.

```tsx
import { AbsoluteFill, Img, staticFile } from 'remotion';
import { CANVAS } from './config';
import { SHOTS } from './shots';
import { theme } from './ui/theme';

/** Меняйте имя кадра, чтобы разметить нужный экран. */
const SHOT = '04-yarmarka.png';
const STEP = 100;

export const Grid: React.FC = () => {
  const size = SHOTS[SHOT];
  if (!size) throw new Error(`Нет кадра ${SHOT}: положите файл и запустите npm run scan`);

  const scale = CANVAS.width / size.width;
  const lines: React.ReactNode[] = [];

  for (let x = 0; x < size.width; x += STEP) {
    lines.push(
      <div
        key={`x${x}`}
        style={{
          position: 'absolute',
          left: x * scale,
          top: 0,
          bottom: 0,
          width: 1,
          background: 'rgba(201,138,58,0.55)',
        }}
      />,
    );
  }
  for (let y = 0; y < size.height; y += STEP) {
    lines.push(
      <div
        key={`y${y}`}
        style={{
          position: 'absolute',
          top: y * scale,
          left: 0,
          right: 0,
          height: 1,
          background: 'rgba(201,138,58,0.55)',
        }}
      />,
      <div
        key={`l${y}`}
        style={{
          position: 'absolute',
          top: y * scale + 4,
          left: 8,
          fontFamily: theme.body,
          fontSize: 22,
          color: theme.ochre,
        }}
      >
        {y}
      </div>,
    );
  }

  return (
    <AbsoluteFill style={{ backgroundColor: theme.ink }}>
      <Img
        src={staticFile(`shots/${SHOT}`)}
        style={{ width: CANVAS.width, height: size.height * scale }}
      />
      {lines}
    </AbsoluteFill>
  );
};
```

- [ ] **Step 3: Переписать `src/Root.tsx`**

```tsx
import { Composition } from 'remotion';
import { CANVAS, FPS, TOTAL_FRAMES } from './config';
import { Grid } from './Grid';
import { loadFonts } from './ui/fonts';
import { Video } from './Video';

loadFonts();

export const RemotionRoot: React.FC = () => (
  <>
    <Composition
      id="Obzor"
      component={Video}
      durationInFrames={TOTAL_FRAMES}
      fps={FPS}
      width={CANVAS.width}
      height={CANVAS.height}
    />
    <Composition
      id="Grid"
      component={Grid}
      durationInFrames={FPS}
      fps={FPS}
      width={CANVAS.width}
      height={CANVAS.height}
    />
  </>
);
```

- [ ] **Step 4: Открыть Studio и убедиться, что композиция играет**

```bash
cd /c/Projects/skaz-video
npm run dev
```

Ожидается: в списке две композиции, `Obzor` длиной 1800 кадров. Сцены со
скриншотами упадут с понятной ошибкой про `npm run scan`, пока кадров нет, —
заставка и финал должны играть.

- [ ] **Step 5: Коммит**

```bash
git add src/Video.tsx src/Grid.tsx src/Root.tsx
git commit -m "Сборка ролика, сетка разметки и композиции"
```

---

### Task 13: Кадры и разметка наездов

**Блокируется съёмкой.** Нужны восемь PNG от заказчика.

**Files:**
- Create: `public/shots/*.png` (восемь файлов)
- Modify: `src/scenario.ts` (координаты `focus`)
- Modify: `src/Grid.tsx:6` (имя размечаемого кадра)

- [ ] **Step 1: Разложить кадры**

Положить присланные файлы в `public/shots/` под именами из спеки:
`01-zakrep.png`, `02-ssylka.png`, `03-glavnyy.png`, `04-yarmarka.png`,
`05-instrumenty.png`, `06-poezdki.png`, `07-obshchiy-dom.png`, `08-sosedi.png`.

- [ ] **Step 2: Пересканировать размеры**

```bash
cd /c/Projects/skaz-video
npm run scan
```

Ожидается: `Кадров найдено: 8` и размеры каждого.

- [ ] **Step 3: Убедиться, что проверки проходят**

```bash
npm run check
```

Ожидается: PASS, все тесты, включая «ссылается только на существующие кадры».

- [ ] **Step 4: Снять координаты по сетке**

Открыть Studio (`npm run dev`), выбрать композицию `Grid`. Для каждого из шести
размечаемых кадров: поменять `SHOT` в `src/Grid.tsx:6`, посмотреть, на каких
координатах лежит нужный элемент, записать четыре числа.

Что размечаем:

| Кадр | Область наезда |
|------|----------------|
| `01-zakrep.png` | полоса закреплённого сообщения под шапкой группы |
| `02-ssylka.png` | ссылка на приложение в тексте сообщения |
| `04-yarmarka.png` | плитка «Ярмарка» или верхнее объявление списка |
| `05-instrumenty.png` | первая карточка инструмента |
| `06-poezdki.png` | ближайшая поездка в списке |
| `07-obshchiy-dom.png` | кнопка «Бронирование помещений» |
| `08-sosedi.png` | карточка соседа в справочнике |

- [ ] **Step 5: Вписать координаты в сценарий**

В `src/scenario.ts` заменить `WHOLE` на снятые прямоугольники, например:

```ts
  {
    kind: 'zoom',
    shot: '04-yarmarka.png',
    focus: { x: 60, y: 940, w: 960, h: 420 },
    title: 'Ярмарка — что продают соседи',
    frames: 222,
  },
```

Когда все шесть размечены, удалить из файла константу `WHOLE` — она больше не
используется.

- [ ] **Step 6: Проверить каждую сцену в Studio**

Пройти по таймлайну `Obzor`. Смотреть: наезд не режет заголовок карточки, титр
не закрывает то, на что наезжаем, проезд по главному экрану доходит до низа.

- [ ] **Step 7: Коммит**

```bash
git add public/shots src/shots.ts src/scenario.ts src/Grid.tsx
git commit -m "Кадры с телефона и разметка наездов"
```

---

### Task 14: Рендер и приёмка

- [ ] **Step 1: Отрендерить**

```bash
cd /c/Projects/skaz-video
npm run render
```

Ожидается: появился `out/obzor.mp4`, длительность 60 секунд.

- [ ] **Step 2: Посмотреть на компьютере**

Проверить: резкость скриншотов, отсутствие рывков на кроссфейдах, читаемость
титров.

- [ ] **Step 3: Проверить через Telegram с телефона**

Отправить `out/obzor.mp4` себе в «Избранное» и посмотреть **с телефона**.
Telegram пережимает видео по-своему. Смотреть: не поплыли ли подписи плиток, не
перекрывает ли полоса плеера титр.

Если картинка поплыла — поднять качество и перерендерить:

```bash
npx remotion render Obzor out/obzor.mp4 --crf 15
```

- [ ] **Step 4: Коммит результата**

```bash
git add -A
git commit -m "Готовый ролик: обзор приложения жителей"
```

Файл `out/obzor.mp4` в git не попадёт (он в `.gitignore`) — публикуется вручную
в группу жителей.

---

## Самопроверка плана

**Покрытие спеки:**

| Требование спеки | Задача |
|---|---|
| Кадр 1080×1920, 30 fps, 1800 кадров | 2, 12 |
| Звука нет | — (ничего не добавляем) |
| Скриншоты с Android, восемь штук | 5, 13 |
| Компоновка B (экран во весь кадр с проездом) | 9 |
| Компоновка C (наезд) | 10 |
| Сцена 2 — два кадра подряд | 10, 11 |
| Девять сцен с титрами из таблицы | 11 |
| Кроссфейд 12 кадров | 12 |
| Отдельный проект вне репозитория сайта | 1 |
| Шрифты копией из residents | 6 |
| Тема из residents.css | 6 |
| Титр не ниже 180 px от края | 6, 7 |
| Проверка отсутствующих кадров | 4, 11 |
| Проверка суммы длительностей | 4, 11 |
| Композиция Grid для разметки | 12, 13 |
| Тесты геометрии камеры | 3 |
| Приёмка в три шага, включая Telegram | 14 |

Незакрытых требований нет.
