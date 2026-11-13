// Порт чистых частей residents/src/TelegramMedia.php и server/tg-media/serve.php
// на node:test (см. пакетный скрипт "test"): сигнатуры картинок, выбор
// file_id, разбор публичного пути /tg-media/... и скачивание оригинала.
// Сеть замокана — fetch подменяется на заглушку, реальные запросы не летят.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  MAX_BYTES,
  THUMB_WIDTHS,
  sniffImageMime,
  chooseLargestFileId,
  mediaUrl,
  parseFileId,
  parseMediaName,
  parseThumbSegment,
  uploadToChannel,
  fetchFromChannel,
} from './telegramMedia';

// ─────────────────────────── Сигнатуры байтов ───────────────────────────

const JPEG = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0, 0, 0, 0, 0, 0, 0, 0]);
const PNG = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 0]);
const WEBP = new Uint8Array([0x52, 0x49, 0x46, 0x46, 0, 0, 0, 0, 0x57, 0x45, 0x42, 0x50]);
const GARBAGE = new Uint8Array(12);

test('sniffImageMime: три сигнатуры из белого списка', () => {
  assert.equal(sniffImageMime(JPEG), 'image/jpeg');
  assert.equal(sniffImageMime(PNG), 'image/png');
  assert.equal(sniffImageMime(WEBP), 'image/webp');
});

test('sniffImageMime: мусор, слишком короткий буфер, обрезанный RIFF', () => {
  assert.equal(sniffImageMime(GARBAGE), null);
  assert.equal(sniffImageMime(new Uint8Array(0)), null);
  assert.equal(sniffImageMime(new Uint8Array([0xff, 0xd8])), null); // JPEG без 3-го байта
  // RIFF без 'WEBP' — это может быть что угодно (wav, avi), не картинка.
  assert.equal(
    sniffImageMime(new Uint8Array([0x52, 0x49, 0x46, 0x46, 0, 0, 0, 0, 0x57, 0x41, 0x56, 0x45])),
    null,
  );
});

// ─────────────────────────── Выбор file_id ───────────────────────────

test('chooseLargestFileId: берёт последнюю (самую крупную) копию', () => {
  assert.equal(
    chooseLargestFileId([{ file_id: 'small' }, { file_id: 'mid' }, { file_id: 'large' }]),
    'large',
  );
  assert.equal(chooseLargestFileId([{ file_id: 'only' }]), 'only');
});

test('chooseLargestFileId: не массив, пусто, мусор вместо file_id', () => {
  assert.equal(chooseLargestFileId(null), null);
  assert.equal(chooseLargestFileId([]), null);
  assert.equal(chooseLargestFileId({ file_id: 'x' }), null);
  // Telegram обязан слать строку; объект/пустая строка — не годится в URL.
  assert.equal(chooseLargestFileId([{ file_id: { nested: true } }, { file_id: '' }]), null);
  assert.equal(chooseLargestFileId([{}, {}]), null);
});

// ─────────────────────────── Разбор пути ───────────────────────────

test('parseFileId: допускает алфавит Bot API, отсекает всё прочее', () => {
  const real = 'AgACAgIAAyEGAATc3awtAAM9argYpH3IA5UDG7lIy4Vsm-QfUp8AAhwdaxvzzMBJ3y';
  assert.equal(parseFileId(real), real);
  assert.equal(parseFileId('abcdefghij'), 'abcdefghij'); // ровно 10 — минимум
  assert.equal(parseFileId('abcdefghi'), null); // 9 — короче легаси-регэкса
  assert.equal(parseFileId('a'.repeat(150)), 'a'.repeat(150));
  assert.equal(parseFileId('a'.repeat(151)), null);
  // Потенциальные инъекции пути: точка (расширение/подделка), слэш, пробел.
  assert.equal(parseFileId('../etc/passwd'), null);
  assert.equal(parseFileId('abc/def'), null);
  assert.equal(parseFileId('abc def'), null);
  assert.equal(parseFileId(''), null);
  assert.equal(parseFileId(123), null);
});

test('parseMediaName: снимает .jpg и оставляет чистый file_id', () => {
  const id = 'AgACAgIAAyEGAATc3awtAAM9argYpH3IA5UDG';
  assert.equal(parseMediaName(`${id}.jpg`), id);
  assert.equal(parseMediaName(id), id);
  // Расширение не .jpg не подделываем: png под видом jpg был бы обманом
  // кэша, а у нас тип ответа считается по байтам — поэтому имя строго jpg.
  assert.equal(parseMediaName(`${id}.png`), null);
  assert.equal(parseMediaName('short.jpg'), null);
  assert.equal(parseMediaName(null), null);
});

test('parseThumbSegment: только белый список ширин, как в serve.php', () => {
  assert.equal(parseThumbSegment('w120'), 120);
  assert.equal(parseThumbSegment('w240'), 240);
  assert.equal(parseThumbSegment('w480'), 480);
  assert.equal(parseThumbSegment('w1080'), null); // не из списка
  assert.equal(parseThumbSegment('w0'), null);
  assert.equal(parseThumbSegment('120'), null); // без w
  assert.equal(parseThumbSegment('wabc'), null);
  // Каждая ширина из списка обязана парситься — иначе раздача молча 400.
  for (const w of THUMB_WIDTHS) assert.equal(parseThumbSegment(`w${w}`), w);
});

// ─────────────────────────── Загрузка (uploadToChannel) ───────────────────────────

/** Временная замена global fetch: handler получает URL, возвращает ответ. */
function mockFetch(handler: (url: string) => Response | Promise<Response>): () => void {
  const original = globalThis.fetch;
  // В этом tsconfig нет DOM-либа — у fetch берём только строку-URL.
  globalThis.fetch = (async (input: unknown) => handler(String(input))) as typeof fetch;
  return () => { globalThis.fetch = original; };
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

/** Канал настроен (или чисто: env вычищается) на время одного теста. */
function withChannelEnv<T>(fn: () => T | Promise<T>): Promise<T> {
  const saved = {
    token: process.env.TG_MEDIA_BOT_TOKEN,
    chat: process.env.TG_MEDIA_CHAT_ID,
    base: process.env.BASE_URL,
  };
  process.env.TG_MEDIA_BOT_TOKEN = '123:TEST-TOKEN';
  process.env.TG_MEDIA_CHAT_ID = '-100123';
  delete process.env.BASE_URL;
  const restore = () => {
    if (saved.token === undefined) delete process.env.TG_MEDIA_BOT_TOKEN;
    else process.env.TG_MEDIA_BOT_TOKEN = saved.token;
    if (saved.chat === undefined) delete process.env.TG_MEDIA_CHAT_ID;
    else process.env.TG_MEDIA_CHAT_ID = saved.chat;
    if (saved.base === undefined) delete process.env.BASE_URL;
    else process.env.BASE_URL = saved.base;
  };
  return Promise.resolve()
    .then(fn)
    .finally(restore);
}

test('uploadToChannel: успех — sendPhoto, берёт наибольшую копию, URL /tg-media/', async () => {
  await withChannelEnv(async () => {
    let seenUrl = '';
    const restore = mockFetch((url) => {
      seenUrl = url;
      return jsonResponse({
        ok: true,
        result: { photo: [{ file_id: 'small' }, { file_id: 'LARGE_ID_123' }] },
      });
    });
    try {
      const res = await uploadToChannel(JPEG);
      assert.ok('url' in res, 'загрузка должна пройти, а не вернуть ошибку');
      if (!('url' in res)) return;
      assert.equal(res.url, 'https://skaz-kray.ru/tg-media/LARGE_ID_123.jpg');
      // fileId нужен роуту загрузки фото задач — из него строится путь tg:<id>.
      assert.equal(res.fileId, 'LARGE_ID_123');
      assert.match(seenUrl, /\/bot123:TEST-TOKEN\/sendPhoto$/);
    } finally {
      restore();
    }
  });
});

test('uploadToChannel: отказ конфига, пустой файл, переразмер, чужой формат', async () => {
  // Без токена/канала — понятная ошибка для редактора, без сетевых вызовов.
  delete process.env.TG_MEDIA_BOT_TOKEN;
  delete process.env.TG_MEDIA_CHAT_ID;
  const noCfg = await uploadToChannel(JPEG);
  assert.ok('error' in noCfg && /не настроена/.test(noCfg.error));

  await withChannelEnv(async () => {
    assert.deepEqual(await uploadToChannel(new Uint8Array(0)), { error: 'Пустой файл.' });

    const restore = mockFetch(() => jsonResponse({ ok: true })); // сеть не должна трогаться
    try {
      const big = new Uint8Array(MAX_BYTES + 1);
      const res = await uploadToChannel(big);
      const msg = 'error' in res ? res.error : `неожиданный URL: ${res.url}`;
      assert.match(msg, /МБ/);

      const bad = await uploadToChannel(GARBAGE);
      assert.deepEqual(bad, { error: 'Можно загружать только JPG, PNG или WEBP.' });
    } finally {
      restore();
    }
  });
});

test('uploadToChannel: Telegram отверг и не ответил — текст готов для виджета', async () => {
  await withChannelEnv(async () => {
    let restore = mockFetch(() => jsonResponse({ ok: false, description: 'chat not found' }, 400));
    try {
      const res = await uploadToChannel(JPEG);
      assert.deepEqual(res, { error: 'chat not found' });
    } finally {
      restore();
    }

    // Битый JSON при 200 — не должно упасть, только сообщение об ошибке.
    restore = mockFetch(() => new Response('not-json', { status: 200 }));
    try {
      const res = await uploadToChannel(JPEG);
      assert.ok('error' in res, 'ответ без ok:true — ошибка, а не URL');
    } finally {
      restore();
    }

    restore = mockFetch(() => { throw new TypeError('fetch failed'); });
    try {
      const res = await uploadToChannel(JPEG);
      assert.deepEqual(res, { error: 'Telegram не ответил, попробуйте ещё раз.' });
    } finally {
      restore();
    }
  });
});

// ─────────────────────────── Раздача (fetchFromChannel) ───────────────────────────

test('fetchFromChannel: успех — file_path и скачивание, тип по байтам', async () => {
  await withChannelEnv(async () => {
    const calls: string[] = [];
    const restore = mockFetch((url) => {
      calls.push(url);
      if (url.includes('/getFile')) {
        return jsonResponse({ ok: true, result: { file_path: 'photos/file_123.jpg' } });
      }
      // Скачивание отдаёт настоящие байты JPEG, заголовок не важен.
      return new Response(JPEG, { status: 200 });
    });
    try {
      const res = await fetchFromChannel('a'.repeat(40));
      assert.ok(!('error' in res), 'ошибки быть не должно');
      if ('error' in res) return;
      assert.equal(res.mime, 'image/jpeg');
      assert.deepEqual([...res.bytes], [...JPEG]);
      assert.equal(calls.length, 2);
      assert.match(calls[0], /\/getFile\?file_id=/);
      assert.match(calls[1], /\/file\/bot123:TEST-TOKEN\/photos\/file_123\.jpg$/);
      // Токен бота — только в исходящих запросах, не в ответе клиенту.
      assert.ok(!JSON.stringify(res).includes('TEST-TOKEN'));
    } finally {
      restore();
    }
  });
});

test('fetchFromChannel: getFile отверг — not_found (404 у клиента)', async () => {
  await withChannelEnv(async () => {
    const restore = mockFetch(() => jsonResponse({ ok: false, description: 'file not found' }));
    try {
      assert.deepEqual(await fetchFromChannel('a'.repeat(40)), { error: 'not_found' });
    } finally {
      restore();
    }
  });
});

test('fetchFromChannel: сбой сети — unavailable (502), а не «нет файла»', async () => {
  await withChannelEnv(async () => {
    // Исключение на getFile — недоступность Telegram, файл может существовать.
    let restore = mockFetch(() => { throw new TypeError('fetch failed'); });
    try {
      assert.deepEqual(await fetchFromChannel('a'.repeat(40)), { error: 'unavailable' });
    } finally {
      restore();
    }

    // Файл есть, но скачать не удалось — тоже unavailable (serve.php: 502).
    restore = mockFetch((url) =>
      url.includes('/getFile')
        ? jsonResponse({ ok: true, result: { file_path: 'photos/file_123.jpg' } })
        : new Response('gone', { status: 404 }),
    );
    try {
      assert.deepEqual(await fetchFromChannel('a'.repeat(40)), { error: 'unavailable' });
    } finally {
      restore();
    }
  });
});

test('fetchFromChannel: подозрительный file_path не уводит запрос на чужой хост', async () => {
  await withChannelEnv(async () => {
    const restore = mockFetch((url) =>
      url.includes('/getFile')
        ? jsonResponse({ ok: true, result: { file_path: '../../evil' } })
        : jsonResponse({}),
    );
    try {
      assert.deepEqual(await fetchFromChannel('a'.repeat(40)), { error: 'unavailable' });
    } finally {
      restore();
    }
  });
});

test('fetchFromChannel: без конфига — no_config, сеть не трогается', async () => {
  delete process.env.TG_MEDIA_BOT_TOKEN;
  delete process.env.TG_MEDIA_CHAT_ID;
  const restore = mockFetch(() => jsonResponse({ ok: true }));
  try {
    assert.deepEqual(await fetchFromChannel('a'.repeat(40)), { error: 'no_config' });
  } finally {
    restore();
  }
});

// ─────────────────────────── mediaUrl ───────────────────────────

test('mediaUrl: дефолтный origin и подрезка хвостового слэша', async () => {
  const saved = process.env.BASE_URL;
  try {
    delete process.env.BASE_URL;
    assert.equal(mediaUrl('ID'), 'https://skaz-kray.ru/tg-media/ID.jpg');

    process.env.BASE_URL = 'https://example.com/';
    assert.equal(mediaUrl('ID'), 'https://example.com/tg-media/ID.jpg');

    process.env.BASE_URL = 'https://example.com';
    assert.equal(mediaUrl('ID'), 'https://example.com/tg-media/ID.jpg');
  } finally {
    if (saved === undefined) delete process.env.BASE_URL;
    else process.env.BASE_URL = saved;
  }
});
