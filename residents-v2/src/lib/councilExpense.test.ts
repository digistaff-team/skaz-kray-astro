// Порт residents/tests/CouncilExpenseApprovalTest.php на node:test (см. пакетный
// скрипт "test"): чистые правила и тексты пайплайна расходов — отправка запроса
// казначею и уведомление исполнителю об отклонении. БД и Bot API здесь не нужны:
// гоняются только функции, работающие со строкой задачи.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  hasExpense, isPrepaid, needsRequest, expenseRequestText, expenseRejectedText, approverName,
} from './councilExpense';

type Task = Parameters<typeof hasExpense>[0] & Parameters<typeof isPrepaid>[0]
  & Parameters<typeof needsRequest>[0] & Parameters<typeof expenseRequestText>[0];

function task(over: Partial<Task> = {}): Task {
  return {
    id: 7,
    title: 'Купить лопаты',
    description: null,
    author: 'Совет',
    assignee: 'Иван Иванов',
    status: 'выполнена',
    spent: 1500,
    expenseCategoryId: 1,
    expenseTiming: 'post',
    expenseStatus: 'none',
    ...over,
  };
}

// --- Момент запроса казначею (легаси test_request_* / test_prepaid_*) ---

test('запрос только при сумме И статье', () => {
  assert.ok(hasExpense(task()));
  assert.ok(!hasExpense(task({ expenseCategoryId: null })), 'сумма без статьи — не запрос');
  assert.ok(!hasExpense(task({ spent: 0 })), 'статья без суммы — не запрос');
  assert.ok(needsRequest(task()), 'выполненная задача с расходом — запрос');
});

test('постоплата не просит денег до выполнения', () => {
  assert.ok(!needsRequest(task({ status: 'в работе' })),
    'постоплата: запрос только когда задача выполнена');
  assert.ok(!needsRequest(task({ status: 'новая' })));
});

test('предоплата просит сразу, до выполнения', () => {
  assert.ok(needsRequest(task({ status: 'новая', expenseTiming: 'pre' })),
    'предоплата: запрос сразу при сохранении');
  assert.ok(needsRequest(task({ status: 'в работе', expenseTiming: 'pre' })));
  assert.ok(!needsRequest(task({ status: 'новая', expenseTiming: 'pre', expenseCategoryId: null })),
    'предоплата без статьи — не запрос');
  assert.ok(isPrepaid(task({ expenseTiming: 'pre' })) && !isPrepaid(task()));
});

test('повторный запрос блокирует статус pending/approved (условия needsRequest)', () => {
  // В requestExpenseIfNeeded гейт по expenseStatus — здесь проверяем вторую
  // половину: после выполнения предоплатных условий не изменилось.
  assert.ok(needsRequest(task({ status: 'выполнена', expenseTiming: 'pre' })));
  assert.ok(needsRequest(task({ status: 'выполнена', expenseTiming: 'post' })));
});

// --- Текст запроса казначею (легаси sendRequest) ---

test('запрос постоплаты: исполнитель, сумма и «Выполнена задача»', () => {
  const text = expenseRequestText(task());
  assert.match(text, /^💰 Запрос на возмещение расходов \(постоплата\)$/m);
  assert.match(text, /^Выполнена задача: .*$/m);
  assert.match(text, /^Исполнитель: Иван Иванов$/m);
  assert.match(text, /^Расходы: 1 500 руб\.$/m);
  assert.ok(!text.includes('Предстоящие расходы'), 'постоплата — «Расходы», не «Предстоящие»');
});

test('запрос предоплаты: «Создана задача» и «Предстоящие расходы»', () => {
  const text = expenseRequestText(task({ expenseTiming: 'pre', status: 'новая' }));
  assert.match(text, /^💰 Запрос на финансирование \(предоплата\)$/m);
  assert.match(text, /^Создана задача: .*$/m);
  assert.match(text, /^Предстоящие расходы: 1 500 руб\.$/m);
});

test('пустой исполнитель — прочерк, HTML в заголовке экранируется', () => {
  const text = expenseRequestText(task({ assignee: '  ', title: 'A <b> & "c"' }));
  assert.match(text, /^Исполнитель: —$/m);
  // parse_mode=HTML требует экранирования (иначе Telegram отвергнет сообщение).
  // Сущности собираем конкатенацией: цельный '<' в литерале декодируется ещё до рантайма.
  const AMP = '&' + 'amp;';
  const escaped = 'A ' + '&' + 'lt;' + 'b' + '&' + 'gt; ' + AMP + ' ' + '&' + 'quot;' + 'c' + '&' + 'quot;';
  assert.ok(text.includes(escaped), 'символы <, >, &, " должны уйти как сущности');
  assert.ok(!text.includes('<b>'), 'сырой тег не должен попасть в сообщение');
});

// --- Уведомление исполнителю об отклонении (легаси notifyAssigneeRejected) ---

test('отклонение постоплаты: упоминает казначея из env/дефолта', () => {
  delete process.env.COUNCIL_EXPENSE_APPROVER;
  const text = expenseRejectedText(task());
  assert.match(text, /^Ваш запрос на возмещение расходов по выполнению Задачи «Купить лопаты» отклонён\./);
  assert.ok(text.includes('Свяжитесь с ' + approverName()));
  assert.ok(!text.includes('предоплаты'), 'постоплата — без упоминания предоплаты');
});

test('отклонение предоплаты: свой зачин', () => {
  process.env.COUNCIL_EXPENSE_APPROVER = 'Пётр Пётров';
  try {
    const text = expenseRejectedText(task({ expenseTiming: 'pre' }));
    assert.match(text, /^Ваш запрос на финансирование предоплаты по Задаче «/);
    assert.ok(text.includes('Свяжитесь с Пётр Пётров'), 'имя казначея берётся из env');
  } finally {
    delete process.env.COUNCIL_EXPENSE_APPROVER;
  }
  assert.equal(approverName(), 'Сергей Шубин', 'дефолт как в легаси');
});