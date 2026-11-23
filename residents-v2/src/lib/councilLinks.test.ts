// Диплинки мини-приложения Совета — порт BotNotify::deepLink и
// CouncilDutyNotify::base/link. Ими пользуются личное уведомление дежурного,
// утренняя рассылка о встрече и уведомления по задачам.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { deepLink, councilAppLink } from './councilLinks';

test('deepLink кодирует путь в base64url без padding', () => {
  assert.equal(
    deepLink('https://t.me/SkazKray_bot/sovet', '/sovet'),
    'https://t.me/SkazKray_bot/sovet?startapp=L3NvdmV0',
  );
});

test('deepLink срезает хвостовой слеш базы', () => {
  assert.equal(
    deepLink('https://t.me/SkazKray_bot/sovet/', '/sovet'),
    'https://t.me/SkazKray_bot/sovet?startapp=L3NvdmV0',
  );
});

test('deepLink для MAX не дублирует ?startapp', () => {
  assert.equal(
    deepLink('https://max.ru/id643900558807_2_bot?startapp', '/sovet'),
    'https://max.ru/id643900558807_2_bot?startapp=L3NvdmV0',
  );
});

test('councilAppLink берёт базу из COUNCIL_APP_LINK, дефолт — как в легаси', () => {
  delete process.env.COUNCIL_APP_LINK;
  assert.equal(councilAppLink(), 'https://t.me/SkazKray_bot/sovet?startapp=L3NvdmV0');

  process.env.COUNCIL_APP_LINK = 'https://t.me/Test_bot/sovet';
  assert.equal(councilAppLink('tg'), 'https://t.me/Test_bot/sovet?startapp=L3NvdmV0');
  delete process.env.COUNCIL_APP_LINK;
});

test('councilAppLink: путь внутри приложения и MAX-база', () => {
  delete process.env.COUNCIL_MAX_APP_LINK;
  assert.equal(
    councilAppLink('max', '/sovet/zadachi'),
    'https://max.ru/id643900558807_2_bot?startapp=L3NvdmV0L3phZGFjaGk',
  );
});
