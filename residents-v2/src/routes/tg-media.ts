import { Router, Request, Response } from 'express';
import multer from 'multer';
import bcrypt from 'bcryptjs';
import sharp from 'sharp';
import {
  MAX_BYTES,
  uploadToChannel,
  fetchFromChannel,
  parseMediaName,
  parseThumbSegment,
} from '../lib/telegramMedia';

/**
 * Загрузка фото в Telegram-канал для Decap CMS — порт связки
 * /tg-media-admin/upload.php + TelegramMedia::upload (легаси, вне git).
 *
 * Клиент — public/cms-tg-image.js (виджеты tgimage/tggallery): FormData с
 * полями `photo` и `password`, в ответ ждёт {url} либо {error}.
 *
 * Гейт — пароль редактора, тот же, что у входа в /editor/. На Vercel нет
 * общего с PHP хранилища, поэтому сверяем bcrypt-хеш из TG_UPLOAD_PASSWORD_HASH
 * (сгенерировать: node -e "console.log(require('bcryptjs').hashSync('пароль',10))").
 *
 * Файл читается в память (multer memory): на serverless нет постоянного
 * диска, а байты всё равно уходят в Telegram и там же остаются — к нам за
 * ними приходят по file_id при раздаче.
 */

const router = Router();

/**
 * Потолок тела запроса к serverless-функции Vercel — 4.5 МБ. Легаси пропускал
 * файлы до 200M (client_max_body_size), но на Vercel запрос больше этого
 * обрывается инфраструктурой ДО нашего кода, и редактор увидел бы непонятную
 * сетевую ошибку вместо «сожмите». Поэтому реальный лимит берём минимумом из
 * потолка платформы и лимита Telegram (MAX_BYTES) и озвучиваем именно его.
 */
const PLATFORM_MAX_BYTES = 4.5 * 1024 * 1024;
const UPLOAD_MAX_BYTES = Math.min(MAX_BYTES, PLATFORM_MAX_BYTES);
const MAX_MB_LABEL = Math.floor(UPLOAD_MAX_BYTES / 1024 / 1024); // «4 МБ» — в меньшую сторону

// Лимит и подписи полей — как в легаси (поле называется photo).
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: UPLOAD_MAX_BYTES, files: 1 },
});

/**
 * Пароль редактора вынесен отдельно: роутер собирается функцией, чтобы
 * гейт можно было прогнать в тестах без env-состояния и multer.
 *
 * Хеш не задан — гейт закрыт (легаси в этом случае тоже не пускал), но текст
 * отвечаем тот же, что и при неверном пароле: сама формулировка нужна
 * клиенту, чтобы сбросить кэш пароля в sessionStorage.
 */
export function createPasswordCheck(hash: string): (received: string) => boolean {
  return (received: string): boolean => {
    if (hash === '' || received === '') return false;
    try {
      return bcrypt.compareSync(received, hash);
    } catch {
      // Битый хеш в env — не причина светить внутренности редактору.
      console.error('TG_UPLOAD_PASSWORD_HASH: некорректный bcrypt-хеш');
      return false;
    }
  };
}

const passwordOk = (received: string): boolean =>
  createPasswordCheck(process.env.TG_UPLOAD_PASSWORD_HASH || '')(received);

/** POST /api/tg-media/upload — одно фото → {url}. */
router.post('/upload', (req: Request, res: Response) => {
  upload.single('photo')(req, res, (err: unknown) => {
    if (err) {
      const code = (err as { code?: string }).code;
      res.status(code === 'LIMIT_FILE_SIZE' ? 413 : 400).json({
        // Лимит multer — UPLOAD_MAX_BYTES (не MAX_BYTES): если редактору
        // показать «10 МБ», файл на 5 МБ упрётся в потолок платформы с
        // чужой, сетевой ошибкой вместо нашей подсказки.
        error: code === 'LIMIT_FILE_SIZE'
          ? `Файл больше ${MAX_MB_LABEL} МБ — сожмите перед загрузкой.`
          : 'Не удалось прочитать файл.',
      });
      return;
    }

    const file = req.file;
    if (!file) {
      res.status(400).json({ error: 'Не передан файл.' });
      return;
    }
    if (!passwordOk(String(req.body?.password ?? ''))) {
      res.status(403).json({ error: 'Неверный пароль редактора.' });
      return;
    }

    uploadToChannel(new Uint8Array(file.buffer))
      .then((result) => {
        if ('error' in result) {
          res.status(502).json(result);
          return;
        }
        res.status(201).json(result);
      })
      .catch((e: unknown) => {
        console.error('tg-media upload error:', e);
        res.status(500).json({ error: 'Ошибка загрузки.' });
      });
  });
});

// ─────────────────────────── Раздача ───────────────────────────

/**
 * Заголовки кэша — те же, что в serve.php: адрес по file_id неизменяем
 * (новое фото получает новый id), поэтому год с immutable. На Vercel кэш
 * общий для всех инстансов, так что копия в памяти нужна только как
 * «тёплая» — при промахе байты просто уйдут в Telegram ещё раз.
 */
const CACHE_CONTROL = 'public, max-age=31536000, immutable';
const MAX_THUMB_ENTRIES = 64;

/**
 * Кеш миниатюр в памяти инстанса. Миниатюры нельзя кэшировать полностью —
 * их нет в Telegram (там только оригинал), и без кеша каждый показ карточки
 * задачи заново тянул бы оригинал. Ограничение по числу записей, чтобы
 * serverless-инстанс не рос бесконечно; вытеснение — самое старое первым.
 */
const thumbCache = new Map<string, Buffer>();

function rememberThumb(key: string, buf: Buffer): void {
  if (thumbCache.size >= MAX_THUMB_ENTRIES) {
    const oldest = thumbCache.keys().next();
    if (!oldest.done) thumbCache.delete(oldest.value);
  }
  thumbCache.set(key, buf);
}

/**
 * Миниатюра шириной w (высоту не увеличиваем), всё в JPEG — как makeThumb
 * в serve.php с качеством 82. Ошибка sharp (битые байты, не он вообще) —
 * повод отдать оригинал: картинка важнее экономии трафика.
 */
async function makeThumb(bytes: Uint8Array, width: number): Promise<Buffer | null> {
  try {
    return await sharp(Buffer.from(bytes))
      .resize({ width, withoutEnlargement: true })
      .jpeg({ quality: 82 })
      .toBuffer();
  } catch (err) {
    console.error('tg-media thumb error:', err instanceof Error ? err.message : String(err));
    return null;
  }
}

/**
 * Отдача файла: 404 — id не существует, 502 — Telegram недоступен или не
 * настроен, 400 — мусор в пути (файла за ним всё равно нет, но так ошибка
 * не маскируется под «нет файла»).
 */
function sendMedia(res: Response, bytes: Uint8Array | Buffer, mime: string | null): void {
  // Telegram копирует Content-Type исходного файла, но оригинал может быть
  // PNG или WEBP — тип отдаём по факту, иначе браузер его не покажет.
  res.setHeader('Content-Type', mime ?? 'image/jpeg');
  res.setHeader('Cache-Control', CACHE_CONTROL);
  res.setHeader('Content-Length', String(bytes.length));
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.status(200).end(Buffer.from(bytes));
}

function fail(res: Response, code: 'not_found' | 'unavailable' | 'no_config'): void {
  if (code === 'not_found') {
    res.status(404).json({ error: 'Фото не найдено' });
    return;
  }
  if (code === 'no_config') {
    console.error('tg-media: нет TG_MEDIA_CHAT_ID / токена бота — раздача недоступна');
  }
  res.status(502).json({ error: 'Фото временно недоступно' });
}

/**
 * GET /tg-media/:id.jpg — оригинал. Публичный (без гейта): ссылки уже
 * проставлены в контенте постов сайта, у них в HTML нет ни токена, ни
 * cookie. Токен бота наружу не уходит — его носит только наш исходящий
 * запрос к Bot API.
 */
router.get('/:name', async (req: Request, res: Response) => {
  const fileId = parseMediaName(req.params.name);
  if (!fileId) {
    res.status(400).json({ error: 'Некорректная ссылка' });
    return;
  }
  const got = await fetchFromChannel(fileId);
  if ('error' in got) {
    console.error(`tg-media serve ${fileId}: ${got.error}`);
    fail(res, got.error);
    return;
  }
  sendMedia(res, got.bytes, got.mime);
});

/** GET /tg-media/w<W>/:name — миниатюра из белого списка ширин. */
router.get('/:thumb/:name', async (req: Request, res: Response) => {
  // Неизвестная ширина — 400, а не молчаливый оригинал: клиент должен
  // узнать об ошибке в разметке сразу (как в serve.php).
  const width = parseThumbSegment(req.params.thumb);
  if (width === null) {
    res.status(400).json({ error: 'Неизвестный размер' });
    return;
  }
  const fileId = parseMediaName(req.params.name);
  if (!fileId) {
    res.status(400).json({ error: 'Некорректная ссылка' });
    return;
  }

  const key = `w${width}/${fileId}`;
  const cached = thumbCache.get(key);
  if (cached) {
    sendMedia(res, cached, 'image/jpeg');
    return;
  }

  const got = await fetchFromChannel(fileId);
  if ('error' in got) {
    console.error(`tg-media thumb ${fileId}: ${got.error}`);
    fail(res, got.error);
    return;
  }

  const thumb = await makeThumb(got.bytes, width);
  if (thumb === null) {
    // sharp не справился — отдаём оригинал (serve.php в этом случае тоже
    // подменял путь на исходный файл).
    sendMedia(res, got.bytes, got.mime);
    return;
  }
  rememberThumb(key, thumb);
  sendMedia(res, thumb, 'image/jpeg');
});

export { router as tgMediaRoutes };
