// Порт residents/tests/TelegramWebAppTest.php на node:test (см. пакетный
// скрипт "test"). Только криптография и decodeStartParam — без БД и сети.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'crypto';

import { verifyInitData, decodeStartParam } from './telegram';

const TOKEN = '123456:TEST-BOT-TOKEN';
const TG_OPTS = { allowSignatureVariant: true } as const;

/** Собирает валидный initData с корректной подписью (как это делает Telegram). */
function makeInitData(fields: Record<string, string>, token: string): string {
  const pairs = Object.keys(fields).map((k) => k + '=' + fields[k]);
  pairs.sort();
  const secret = crypto.createHmac('sha256', 'WebAppData').update(token).digest();
  const hash = crypto.createHmac('sha256', secret).update(pairs.join('\n')).digest('hex');
  const parts = Object.keys(fields).map(
    (k) => encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]),
  );
  parts.push('hash=' + hash);
  return parts.join('&');
}

test('valid initData returns user', () => {
  const user = JSON.stringify({ id: 111, first_name: 'Иван', last_name: 'Петров', username: 'ivan' });
  const initData = makeInitData({ auth_date: String(Math.floor(Date.now() / 1000)), user }, TOKEN);
  const res = verifyInitData(initData, TOKEN, TG_OPTS);
  assert.ok(res);
  assert.equal(res.id, '111');
  assert.equal(res.firstName, 'Иван');
  assert.equal(res.username, 'ivan');
});

test('wrong bot token rejected', () => {
  const user = JSON.stringify({ id: 111, first_name: 'Иван' });
  const initData = makeInitData({ auth_date: String(Math.floor(Date.now() / 1000)), user }, TOKEN);
  assert.equal(verifyInitData(initData, 'other:TOKEN', TG_OPTS), null);
});

test('tampered user rejected', () => {
  const user = JSON.stringify({ id: 111 });
  const initData = makeInitData({ auth_date: String(Math.floor(Date.now() / 1000)), user }, TOKEN);
  // Подменяем user после подписи — хеш больше не сходится.
  const tampered = initData.replace(
    encodeURIComponent(user),
    encodeURIComponent(JSON.stringify({ id: 999 })),
  );
  assert.equal(verifyInitData(tampered, TOKEN, TG_OPTS), null);
});

test('stale auth_date rejected', () => {
  const user = JSON.stringify({ id: 111 });
  const old = String(Math.floor(Date.now() / 1000) - 90000); // >24ч
  const initData = makeInitData({ auth_date: old, user }, TOKEN);
  assert.equal(verifyInitData(initData, TOKEN, TG_OPTS), null);
});

test('auth_date from the future rejected', () => {
  const user = JSON.stringify({ id: 111 });
  const future = String(Math.floor(Date.now() / 1000) + 600); // > допуска 300с
  const initData = makeInitData({ auth_date: future, user }, TOKEN);
  assert.equal(verifyInitData(initData, TOKEN, TG_OPTS), null);
});

test('canonical signature with signature field accepted (Telegram)', () => {
  // Клиент прислал поле signature — подпись считается канонично (со всеми
  // полями кроме hash); allowSignatureVariant разрешает и вторую трактовку.
  const user = JSON.stringify({ id: 111, first_name: 'А' });
  const initData = makeInitData(
    { auth_date: String(Math.floor(Date.now() / 1000)), signature: 'abc', user },
    TOKEN,
  );
  assert.ok(verifyInitData(initData, TOKEN, TG_OPTS));
});

test('signature-excluded variant accepted only with allowSignatureVariant', () => {
  const now = String(Math.floor(Date.now() / 1000));
  const user = JSON.stringify({ id: 111, first_name: 'А' });
  // Хеш посчитан БЕЗ поля signature — так делает часть клиентов.
  const fields: Record<string, string> = { auth_date: now, signature: 'abc', user };
  const pairs = Object.keys(fields)
    .filter((k) => k !== 'signature')
    .map((k) => k + '=' + fields[k]);
  pairs.sort();
  const secret = crypto.createHmac('sha256', 'WebAppData').update(TOKEN).digest();
  const hash = crypto.createHmac('sha256', secret).update(pairs.join('\n')).digest('hex');
  const parts = Object.keys(fields).map(
    (k) => encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]),
  );
  const initData = parts.join('&') + '&hash=' + hash;

  assert.equal(verifyInitData(initData, TOKEN), null); // строгий канон — отказ
  assert.ok(verifyInitData(initData, TOKEN, TG_OPTS)); // вариант разрешён
});

test('plus in value is decoded as space (PHP urldecode parity)', () => {
  // «+» в значении — пробел: HMAC считается по раздекодированному значению.
  const user = JSON.stringify({ id: 111, first_name: 'Иван Петров' });
  const encodedUser = encodeURIComponent(user).replace(/%20/g, '+');
  const authDate = String(Math.floor(Date.now() / 1000));
  const secret = crypto.createHmac('sha256', 'WebAppData').update(TOKEN).digest();
  const hash = crypto
    .createHmac('sha256', secret)
    .update(['auth_date=' + authDate, 'user=' + user].sort().join('\n'))
    .digest('hex');
  const initData = 'auth_date=' + authDate + '&user=' + encodedUser + '&hash=' + hash;

  const res = verifyInitData(initData, TOKEN, TG_OPTS);
  assert.ok(res);
  assert.equal(res.firstName, 'Иван Петров');
});

test('empty and malformed inputs rejected', () => {
  assert.equal(verifyInitData('', TOKEN, TG_OPTS), null);
  assert.equal(verifyInitData('auth_date=1&hash=x', '', TG_OPTS), null);
  assert.equal(verifyInitData('auth_date=1&hash=' + 'a'.repeat(64), TOKEN, TG_OPTS), null);
});

test('decodeStartParam keeps only /poselenie/ paths', () => {
  const good = Buffer.from('/poselenie/x').toString('base64url');
  assert.equal(decodeStartParam(good), '/poselenie/x');

  const evil = Buffer.from('https://evil.example/phish').toString('base64url');
  assert.equal(decodeStartParam(evil), '/poselenie/'); // не путь портала

  const admin = Buffer.from('/admin/panel').toString('base64url');
  assert.equal(decodeStartParam(admin), '/poselenie/'); // вне префикса

  assert.equal(decodeStartParam('not-base64!!'), '/poselenie/'); // мусор
  assert.equal(decodeStartParam(''), '/poselenie/'); // пусто
  assert.equal(decodeStartParam(null), '/poselenie/'); // нет параметра
});
