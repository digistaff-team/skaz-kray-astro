// Порт bin/council-meeting-notify.php (часть текста): текст ежедневной рассылки
// о встрече и диплинк мини-приложения. Тексты логики (гейты, notified_for)
// живут в runMeetingNotify и без БД в unit-тесты не выносятся.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { meetingNotifyText } from './meetingNotify';

test('meetingNotifyText повторяет текст council-meeting-notify.php', () => {
  const text = meetingNotifyText(
    '16 ноября 2026, 18:00',
    'Сказочный Терем, 1-й этаж',
    'Елена Моисеенко',
    'Александр Бобков',
    'https://t.me/SkazKray_bot/sovet?startapp=c292ZXQ',
  );
  const lines = text.split('\n');
  assert.equal(lines[0], '🗓 Сегодня встреча Попечительского совета');
  assert.equal(lines[1], '');
  assert.equal(lines[2], '16 ноября 2026, 18:00');
  assert.equal(lines[3], 'Сказочный Терем, 1-й этаж');
  assert.equal(lines[5], 'Дежурный председатель: Елена Моисеенко');
  assert.equal(lines[6], 'Дежурный секретарь: Александр Бобков');
  assert.equal(lines[8], 'Повестка встречи в приложении:');
  assert.equal(lines[9], 'https://t.me/SkazKray_bot/sovet?startapp=c292ZXQ');
});

test('meetingNotifyText переносит пустые поля без «undefined»', () => {
  const text = meetingNotifyText('', '', '', '', 'https://t.me/x');
  assert.ok(!text.includes('undefined'));
  assert.match(text, /Дежурный председатель: $/m);
});