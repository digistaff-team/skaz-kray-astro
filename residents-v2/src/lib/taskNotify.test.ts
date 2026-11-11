// Порт residents/src/Controller/Council/TaskController::notifyAssignee (сборка
// текста и клавиатуры) на node:test — см. пакетный скрипт "test".

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  taskNotifyText, taskNotifyKeyboard, priorityView, ruDate, tasksDeepLink,
} from './taskNotify';

test('priorityView: мужской род + цветной кружок', () => {
  assert.deepEqual(priorityView('высокая'), ['высокий', '🔴']);
  assert.deepEqual(priorityView('средняя'), ['средний', '🟠']);
  assert.deepEqual(priorityView('низкая'), ['низкий', '🟢']);
  assert.deepEqual(priorityView('лишняя'), ['лишняя', '⚪']);
});

test('ruDate формирует дату как ru_date() в легаси', () => {
  assert.equal(ruDate('2026-11-14'), '14 ноября 2026');
  assert.equal(ruDate('2026-01-05'), '5 января 2026');
  assert.equal(ruDate(''), '');
  assert.equal(ruDate('не дата'), 'не дата');
});

test('tasksDeepLink: base64url(/sovet/zadachi) без padding', () => {
  process.env.COUNCIL_APP_LINK = 'https://t.me/SkazKray_bot/sovet';
  assert.equal(
    tasksDeepLink(),
    'https://t.me/SkazKray_bot/sovet?startapp=L3NvdmV0L3phZGFjaGk',
  );
  delete process.env.COUNCIL_APP_LINK;
});

test('чужой исполнитель: заголовок, поставивший, приоритет, срок и ссылка', () => {
  const text = taskNotifyText({
    assignee: 'Иван Иванов',
    author: 'Пётр Петров',
    title: 'Починить забор',
    priority: 'высокая',
    dueDate: '2026-11-14',
    link: 'https://t.me/x?startapp=abc',
  });
  assert.match(text, /^👋 Вам поставлена задача:/);
  assert.match(text, /😃 Поставил: Пётр Петров/);
  assert.match(text, /🌟 Починить забор/);
  assert.match(text, /🔴 Приоритет: высокий/);
  assert.match(text, /⏰ Срок: 14 ноября 2026/);
  assert.match(text, /<a href="https:\/\/t\.me\/x\?startapp=abc">Подробнее<\/a>$/);
});

test('самоназначение: без строки «Поставил», свой заголовок', () => {
  const text = taskNotifyText({
    assignee: 'Иван Иванов',
    author: 'Иван Иванов',
    title: 'Своя задача',
    priority: 'низкая',
    dueDate: null,
    link: 'https://t.me/x?startapp=abc',
  });
  assert.match(text, /^👋 Вы взяли себе задачу/);
  assert.ok(!text.includes('Поставил:'), 'свой заголовок не назначают');
  assert.match(text, /⏰ Срок: не задан/);
  assert.match(text, /🟢 Приоритет: низкий/);
});

test('HTML в названии экранируется (сообщение уходит с parse_mode=HTML)', () => {
  const text = taskNotifyText({
    assignee: 'Иван Иванов',
    author: 'Пётр Петров',
    title: 'Купить <бензин> & "расходники"',
    priority: 'средняя',
    dueDate: null,
    link: 'https://t.me/x?startapp=abc',
  });
  assert.ok(text.includes('Купить &lt;бензин&gt; &amp; &quot;расходники&quot;'));
  assert.ok(!text.includes('<бензин>'), 'сырые теги не должны уходить в parse_mode=HTML');
});

test('кнопки: чужая задача — «Отказался», своя — только «Взял в работу»', () => {
  const other = JSON.parse(taskNotifyKeyboard(42, false));
  assert.deepEqual(other.inline_keyboard[0], [
    { text: '✅ Взял в работу', callback_data: 't:42:take' },
    { text: '🚫 Отказался', callback_data: 't:42:decline' },
  ]);

  const self = JSON.parse(taskNotifyKeyboard(42, true));
  assert.deepEqual(self.inline_keyboard[0], [
    { text: '✅ Взял в работу', callback_data: 't:42:take' },
  ]);
});