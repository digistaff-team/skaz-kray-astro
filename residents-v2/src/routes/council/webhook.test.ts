// Порт residents/tests/CouncilDutyNotifyTest.php + CouncilBotWebhookTest (чистые
// части) на node:test: текст/клавиатура уведомления дежурного и замена строки
// статуса в сообщении задачи. Без БД и сети — см. пакетный скрипт "test".

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { dutyNotifyText, dutyAckKeyboard, DUTY_ACK_LABEL } from '../../lib/dutyNotify';
import { withStatusLine } from './webhook';

test('dutyNotifyText with ack button for Telegram', () => {
  const text = dutyNotifyText(
    'Сергей Шубин', '28 сентября 2026, 18:00', 'Терем', 'https://t.me/x?startapp=y', true,
  );
  assert.match(text, /^Сергей, Вы — дежурный председатель/);
  assert.match(text, /28 сентября 2026, 18:00/);
  assert.match(text, /Терем/);
  assert.match(text, /Подтвердите принятие дежурства, нажав кнопку под сообщением\./);
  assert.match(text, /https:\/\/t\.me\/x\?startapp=y$/);
});

test('dutyNotifyText without ack button (MAX)', () => {
  const text = dutyNotifyText('Сергей Шубин', '28 сентября 2026, 18:00', '', 'https://max.ru/x');
  assert.ok(!text.includes('Подтвердите принятие дежурства'), 'в MAX кнопок нет — строка не должна врать');
});

test('dutyNotifyText handles empty chair and date', () => {
  const text = dutyNotifyText('', '', '', 'https://t.me/x');
  assert.match(text, /Дата встречи уточняется/);
  assert.ok(text.startsWith('Вы — дежурный председатель'), 'без имени — без обращения');
});

test('dutyAckKeyboard embeds rotation index (старая кнопка не подтверждает новое дежурство)', () => {
  const kb = JSON.parse(dutyAckKeyboard(3));
  const btn = kb.inline_keyboard[0][0];
  assert.equal(btn.text, '✅ ' + DUTY_ACK_LABEL);
  assert.equal(btn.callback_data, 'd:ack:3');
});

test('withStatusLine replaces служебные строки статуса', () => {
  const base = 'Ольга, Вы — дежурный председатель\n\nОписание';
  assert.equal(
    withStatusLine(base + '\n\n✅ Вы взяли задачу в работу', 'Вы выполнили задачу на 50%'),
    base + '\n\nВы выполнили задачу на 50%',
  );
  assert.equal(
    withStatusLine(base + '\n\nВы выполнили задачу на 50%', '🎉 Вы выполнили эту задачу, большое спасибо!'),
    base + '\n\n🎉 Вы выполнили эту задачу, большое спасибо!',
  );
});

test('withStatusLine appends when status line absent', () => {
  const base = 'Сообщение без статусной строки';
  assert.equal(withStatusLine(base, '✅ Вы взяли задачу в работу'), base + '\n\n✅ Вы взяли задачу в работу');
});

test('withStatusLine не съедает $ как замену regex', () => {
  // В легаси $ в подстановке литеральный (addcslashes) — здесь тоже не должен
  // превращаться в группу захвата.
  const base = 'Строка';
  assert.equal(withStatusLine(base, 'Прогресс: $100'), base + '\n\nПрогресс: $100');
});