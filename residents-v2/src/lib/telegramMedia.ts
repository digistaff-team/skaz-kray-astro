// Загрузка фото в приватный Telegram-канал «Skaz-Kray Media» — порт
// residents/src/TelegramMedia.php (+ Validator::imageMime и getimagesize).
//
// Легаси-цепочка: виджет Decap (public/cms-tg-image.js) шлёт файл на
// /tg-media-admin/upload.php, тот дёргает sendPhoto и отдаёт постоянный URL
// /tg-media/<file_id>.jpg. Байты при этом хранит Telegram, а не наш сервер, —
// поэтому переезд на Vercel (где нет постоянного диска) ничего не ломает:
// файл в канале живёт вечно, а раздачу обеспечивает серверный рендер по file_id.
//
// process.env читается строго внутри функций: на Vercel module-level чтение
// даёт undefined (модуль инициализируется до подстановки переменных функции).

const API = 'https://api.telegram.org';
const TIMEOUT_MS = 25_000;

/**
 * Максимум Bot API для sendPhoto — 10 МБ. В легаси лимита в коде не было
 * (только client_max_body_size 200M у nginx), и файл крупнее 10 МБ Telegram
 * отвергал уже на своей стороне неясной ошибкой; здесь он отсекается заранее
 * с понятным текстом.
 */
export const MAX_BYTES = 10 * 1024 * 1024;

/** Допустимые типы — тот же список, что у Validator::imageMime в легаси. */
export const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'] as const;
export type ImageMime = (typeof IMAGE_MIMES)[number];

const EXT_BY_MIME: Record<ImageMime, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

/**
 * Тип по сигнатуре байтов — замена PHP getimagesize. Полагаться на
 * file.mimetype из формы нельзя: его задаёт клиент, и под ним может лежать
 * что угодно (легаси тоже смотрел на содержимое файла, а не на заголовок).
 * null — не картинка из белого списка.
 */
export function sniffImageMime(buf: Uint8Array): ImageMime | null {
  if (buf.length < 12) return null;
  // JPEG: FF D8 FF
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image/jpeg';
  // PNG: 89 50 4E 47 0D 0A 1A 0A
  if (
    buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47 &&
    buf[4] === 0x0d && buf[5] === 0x0a && buf[6] === 0x1a && buf[7] === 0x0a
  ) return 'image/png';
  // WEBP: 'RIFF' <size> 'WEBP'
  const riff = String.fromCharCode(buf[0], buf[1], buf[2], buf[3]);
  const webp = String.fromCharCode(buf[8], buf[9], buf[10], buf[11]);
  if (riff === 'RIFF' && webp === 'WEBP') return 'image/webp';
  return null;
}

/**
 * file_id самой большой копии. Telegram отдаёт photo[] по возрастанию размера
 * (сжатые превью … оригинал до ~1280px), поэтому берём последний — как end()
 * в легаси. Массив приходит из JSON, его тип нам не доверяем: фильтруем
 * элементы без file_id, чтобы не получить «undefined.jpg».
 */
export function chooseLargestFileId(photo: unknown): string | null {
  if (!Array.isArray(photo) || photo.length === 0) return null;
  for (let i = photo.length - 1; i >= 0; i -= 1) {
    const item = photo[i] as { file_id?: unknown } | null;
    const id = item?.file_id;
    if (typeof id === 'string' && id !== '') return id;
  }
  return null;
}

/** Постоянный URL картинки — тот же формат, что уже зашит в 21 ссылке контента. */
export function mediaUrl(fileId: string): string {
  const base = (process.env.BASE_URL || 'https://skaz-kray.ru').replace(/\/+$/, '');
  return `${base}/tg-media/${fileId}.jpg`;
}

/** Ответ Bot API на sendPhoto — поля нам нужны только эти. */
interface SendPhotoResponse {
  ok?: boolean;
  description?: string;
  result?: { photo?: unknown };
}

/** Конфиг канала; null — настроен неполностью (легаси в этом случае молча фолбэчился). */
export function channelConfig(): { botToken: string; chatId: string } | null {
  const botToken = process.env.TG_MEDIA_BOT_TOKEN || process.env.SKAZKRAY_BOT_TOKEN || '';
  const chatId = process.env.TG_MEDIA_CHAT_ID || '';
  if (botToken === '' || chatId === '') return null;
  return { botToken, chatId };
}

export type UploadResult =
  | {
      /**
       * file_id — путь `tg:<file_id>` в images (фото задач и т.п.);
       * url — абсолютная ссылка /tg-media/<file_id>.jpg для редактора.
       */
      fileId: string;
      url: string;
    }
  | { error: string };

/**
 * sendPhoto в канал и постоянный URL. Ошибку возвращает текстом, готовым к
 * показу редактору: виджет Decap выводит именно его, а фолбэк «сохранить
 * локально» на Vercel невозможен (файловой системы нет), поэтому заглушать
 * ошибку null-ем, как делал легаси, нельзя.
 */
export async function uploadToChannel(buf: Uint8Array): Promise<UploadResult> {
  const cfg = channelConfig();
  if (!cfg) return { error: 'Загрузка фото не настроена (нет TG_MEDIA_CHAT_ID).' };
  if (buf.length === 0) return { error: 'Пустой файл.' };
  if (buf.length > MAX_BYTES) {
    return { error: `Файл больше ${Math.round(MAX_BYTES / 1024 / 1024)} МБ — сожмите перед загрузкой.` };
  }

  const mime = sniffImageMime(buf);
  if (!mime) return { error: 'Можно загружать только JPG, PNG или WEBP.' };

  // Мultipart в точности как CURLOPT_POSTFIELDS с CURLFile в легаси: Telegram
  // определяет фото по содержимому, имя и mime влияют лишь на подпись в канале.
  const form = new FormData();
  form.append('chat_id', cfg.chatId);
  form.append('photo', new Blob([new Uint8Array(buf)], { type: mime }), `photo.${EXT_BY_MIME[mime]}`);

  let data: SendPhotoResponse | null = null;
  try {
    const res = await fetch(`${API}/bot${cfg.botToken}/sendPhoto`, {
      method: 'POST',
      body: form,
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    // res.json() типизирован как unknown (в этом tsconfig нет DOM-либа) —
    // приводим к объявленной форме ответа, дальше работаем через optional-поля.
    data = (await res.json().catch(() => null)) as SendPhotoResponse | null;
    if (!res.ok) {
      console.error(`TelegramMedia.sendPhoto: HTTP ${res.status} ${data?.description ?? ''}`.trim());
    }
  } catch (err) {
    console.error('TelegramMedia.sendPhoto error:', err instanceof Error ? err.message : String(err));
    return { error: 'Telegram не ответил, попробуйте ещё раз.' };
  }

  if (!data?.ok) return { error: data?.description || 'Telegram отверг загрузку.' };
  const fileId = chooseLargestFileId(data.result?.photo);
  if (!fileId) return { error: 'Telegram не вернул file_id.' };
  return { fileId, url: mediaUrl(fileId) };
}

// ─────────────────────────── Раздача по file_id ───────────────────────────

/**
 * Ширины миниатюр — белый список ровно как const THUMB_WIDTHS в
 * server/tg-media/serve.php. Без него любой желающий заказал бы произвольный
 * ресайз и засыпал кэш вариантами одного фото.
 */
export const THUMB_WIDTHS = [120, 240, 480] as const;
export type ThumbWidth = (typeof THUMB_WIDTHS)[number];

/**
 * file_id из пути. Telegram выдаёт их в base64url, но конкретный алфавит —
 * его внутреннее дело, поэтому проверяем только форму: 10–150 символов
 * [A-Za-z0-9_-] (тот же диапазон, что regexp в serve.php). Значение уходит
 * в URL Bot API, так что всё остальное — потенциальная инъекция пути.
 */
export function parseFileId(raw: unknown): string | null {
  if (typeof raw !== 'string') return null;
  return /^[A-Za-z0-9_-]{10,150}$/.test(raw) ? raw : null;
}

/**
 * Строка приходит либо как «<file_id>.jpg» (наш публичный формат), либо как
 * «<file_id>» — разбираем оба, а всё постороннее отбрасываем. Возвращает id
 * без расширения: расширение в публичном URL декоративное (nginx-эпоха), тип
 * отдаём по фактическим байтам.
 */
export function parseMediaName(raw: unknown): string | null {
  if (typeof raw !== 'string') return null;
  const name = raw.endsWith('.jpg') ? raw.slice(0, -4) : raw;
  return parseFileId(name);
}

/** Ширина миниатюры из сегмента пути («w240»); null — сегмент не наш. */
export function parseThumbSegment(seg: unknown): ThumbWidth | null {
  if (typeof seg !== 'string') return null;
  const m = /^w(\d{1,4})$/.exec(seg);
  if (!m) return null;
  const w = Number(m[1]);
  return (THUMB_WIDTHS as readonly number[]).includes(w) ? (w as ThumbWidth) : null;
}

export type MediaFetch =
  | { bytes: Uint8Array; mime: string | null }
  | { error: 'not_found' | 'unavailable' | 'no_config' };

/**
 * Байты оригинала из Telegram: getFile → file_path → скачивание файла.
 * Ответы parse-ятся как unknown: Bot API может вернуть что угодно, а тип
 * нам нужен только для полей ok/result.
 *
 * Ошибка различается по смыслу, потому что коды ответа разные: «файла нет»
 * (getFile вернул ok:false — id не существует или файл удалён из канала) —
 * 404, «Telegram не ответил» — 502. Как и в serve.php, ошибку скачивания
 * после успешного getFile считаем 502, а не 404.
 */
export async function fetchFromChannel(fileId: string): Promise<MediaFetch> {
  const cfg = channelConfig();
  if (!cfg) return { error: 'no_config' };

  const fileUrl = await (async (): Promise<string | null | undefined> => {
    try {
      const res = await fetch(
        `${API}/bot${cfg.botToken}/getFile?file_id=${encodeURIComponent(fileId)}`,
        { signal: AbortSignal.timeout(TIMEOUT_MS) },
      );
      const data = (await res.json().catch(() => null)) as {
        ok?: boolean;
        result?: { file_path?: unknown };
      } | null;
      if (data?.ok !== true) return null; // id не существует / бот не в канале
      const path = data.result?.file_path;
      return typeof path === 'string' && path !== '' ? path : null;
    } catch (err) {
      console.error('TelegramMedia.getFile error:', err instanceof Error ? err.message : String(err));
      return undefined; // сеть/таймаут — это не «файла нет»
    }
  })();

  if (fileUrl === null) return { error: 'not_found' };
  if (fileUrl === undefined) return { error: 'unavailable' };

  try {
    // file_path — относительный, но приходит из ответа Bot API; удостоверяемся,
    // что он не уводит запрос на чужой хост вместе с токеном в пути.
    if (!/^[A-Za-z0-9/_.-]+$/.test(fileUrl) || fileUrl.includes('..')) {
      console.error('TelegramMedia.getFile: подозрительный file_path');
      return { error: 'unavailable' };
    }
    const res = await fetch(`${API}/file/bot${cfg.botToken}/${fileUrl}`, {
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!res.ok) return { error: 'unavailable' };
    const bytes = new Uint8Array(await res.arrayBuffer());
    // Тип — по байтам: заголовок Telegram копирует из исходного файла, но
    // доверять ему незачем, у нас уже есть sniff-разбор.
    return { bytes, mime: sniffImageMime(bytes) };
  } catch (err) {
    console.error('TelegramMedia.file error:', err instanceof Error ? err.message : String(err));
    return { error: 'unavailable' };
  }
}
