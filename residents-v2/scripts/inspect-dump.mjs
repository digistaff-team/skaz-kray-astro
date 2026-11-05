// Инспекция дампа MariaDB перед миграцией в PostgreSQL:
// проверяем валидность UTF-8 и реальный набор MySQL-escape-последовательностей,
// чтобы парсер значений не молча портил данные.
import { readFileSync } from 'node:fs';

const file = process.argv[2] ?? 'residents_db.sql';
const buf = readFileSync(file);
const s = buf.toString('utf8');

// Если байты после декодирования в UTF-8 и обратно дают тот же буфер — кодировка чистая.
const roundtrip = Buffer.from(s, 'utf8').equals(buf);
console.log('файл:', file, buf.length, 'байт');
console.log('валидный UTF-8 roundtrip:', roundtrip);

// Собираем все символы, стоящие сразу после бэкслэша (внутри строковых литералов
// MySQL экранирует так: \\, \', \n, \r, \0, \Z, \" ...).
const after = {};
for (let i = 0; i < s.length; i++) {
  if (s[i] !== '\\') continue;
  const n = s[i + 1] ?? '(EOF)';
  const key = n === '\n' ? 'LF' : n === '\r' ? 'CR' : n === '\t' ? 'TAB' : n === ' ' ? 'SPC' : n;
  after[key] = (after[key] ?? 0) + 1;
  i++;
}
console.log('символы после \\ :', JSON.stringify(after, null, 0));
console.log('всего бэкслэшей:', (s.match(/\\/g) || []).length);
console.log('CR в файле:', (s.match(/\r/g) || []).length);
