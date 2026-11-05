/**
 * Перенос данных из дампа MariaDB (skazkray_residents) в PostgreSQL (Supabase).
 *
 * Почему именно так, а не «скормировать дамп psql'у»:
 *  1. Таблицы уже созданы `prisma db push` — со всеми @map, FK, индексами и
 *     типами из schema.prisma. Дамп же содержит MySQL-DDL, который в PG нерелевантен
 *     и при пересоздании таблиц снёс бы Prisma-соглашения. Поэтому скрипт
 *     НИЧЕГО не создаёт: только INSERT в существующие таблицы.
 *  2. Типы колонок берутся из information_schema PostgreSQL, а не из дампа —
 *     это единственный способ гарантированно попасть в те же типы, что видит Prisma
 *     (boolean из tinyint(1), date из datetime-подмножества, numeric, bigint).
 *  3. INSERT параметризованы: MySQL-эскейпинг раскодируется в настоящие строки,
 *     поэтому в PG не остаются последовательности вида \n и \" и не ломается UTF-8.
 *  4. Порядок загрузки считается топосортировкой по реальным FK в PG, а не хардкодом.
 *  5. Нулевые даты MySQL ('0000-00-00 00:00:00') PG отвергает — такие значения
 *     становятся NULL, а не обрывают загрузку.
 *
 * Запуск:
 *   node residents-v2/scripts/migrate-mysql-to-pg.mjs --dry-run    # разбор + сверка схем
 *   node residents-v2/scripts/migrate-mysql-to-pg.mjs              # загрузка
 *   node residents-v2/scripts/migrate-mysql-to-pg.mjs --truncate   # сначала очистить PG
 *
 * Строка подключения берётся из DATABASE_URL в residents-v2/.env.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import pg from 'pg';
import dotenv from 'dotenv';

const HERE = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.join(HERE, '..', '.env') });

const DUMP = path.join(HERE, '..', '..', 'residents_db.sql');
const DRY_RUN = process.argv.includes('--dry-run');
const TRUNCATE = process.argv.includes('--truncate');

// ───────────────────────── парсер дампа ─────────────────────────

/**
 * Разбивает SQL на инструкции по ';' вне строковых литералов и комментариев.
 * Кавычки MySQL '...' и "..." считаются строками, `...` — идентификатором.
 */
function splitStatements(sql) {
  const out = [];
  let cur = '';
  let str = null; // текущий символ-ограничитель строки
  for (let i = 0; i < sql.length; i++) {
    const ch = sql[i];
    const next = sql[i + 1];

    if (str) {
      cur += ch;
      if (ch === '\\' && str !== '`') {
        // Эскейп-последовательность MySQL: берём следующий символ как есть.
        cur += sql[++i] ?? '';
        continue;
      }
      if (ch === str && next === str) {
        // Удвоенная кавычка внутри строки ('it''s') — не закрытие.
        cur += sql[++i];
        continue;
      }
      if (ch === str) str = null;
      continue;
    }

    if (ch === '-' && next === '-' && (sql[i + 2] === ' ' || sql[i + 2] === '\n' || sql[i + 2] === undefined)) {
      while (i < sql.length && sql[i] !== '\n') i++;
      cur += '\n';
      continue;
    }
    if (ch === '/' && next === '*') {
      // /*! ... */ — MariaDB условные комментарии: содержимое это или мусор
      // (SET/LOCK/ALTER), в PG оно не нужно ни в каком виде — выбрасываем целиком.
      const end = sql.indexOf('*/', i);
      i = end === -1 ? sql.length : end + 1;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') {
      str = ch;
      cur += ch;
      continue;
    }
    if (ch === ';') {
      out.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  if (cur.trim()) out.push(cur);
  return out;
}

/** Раскодирует MySQL-строковый литерал в настоящее значение (или null для NULL). */
export function decodeMysqlLiteral(raw) {
  if (raw === undefined) return null;
  const t = raw.trim();
  if (/^NULL$/i.test(t)) return null;

  const q = t[0];
  if (q !== "'" && q !== '"') return t; // число/литерал без кавычек — отдаём как строку

  let s = t.slice(1, -1);
  let out = '';
  for (let i = 0; i < s.length; i++) {
    if (s[i] !== '\\') {
      // Удвоенная кавычка — единственный не-бэкслэшный эскейп.
      if (s[i] === q && s[i + 1] === q) {
        out += q;
        i++;
        continue;
      }
      out += s[i];
      continue;
    }
    const n = s[++i];
    switch (n) {
      case 'n': out += '\n'; break;
      case 'r': out += '\r'; break;
      case 't': out += '\t'; break;
      case 'b': out += '\b'; break;
      case 'f': out += '\f'; break;
      case '0': out += '\0'; break;
      case 'Z': out += '\x1a'; break;
      case '\\': out += '\\'; break;
      case "'": out += "'"; break;
      case '"': out += '"'; break;
      case '\n': break; // перенос строки дампа — не данные
      default: out += n ?? '';
    }
  }
  return out;
}

/** Разбирает `VALUES (...),(...)` на массив строк-массивов значений. */
function parseValuesList(text) {
  const rows = [];
  let depth = 0;
  let str = null;
  let cell = '';
  let row = [];
  const n = text.length;

  for (let i = 0; i < n; i++) {
    const ch = text[i];

    if (str) {
      cell += ch;
      if (ch === '\\' && str !== '`') {
        cell += text[++i] ?? '';
        continue;
      }
      if (ch === str && text[i + 1] === str) {
        cell += text[++i];
        continue;
      }
      if (ch === str) str = null;
      continue;
    }

    if (ch === "'" || ch === '"') {
      str = ch;
      cell += ch;
      continue;
    }
    if (ch === '(') {
      depth++;
      if (depth === 1) {
        cell = '';
        row = [];
        continue;
      }
    }
    if (ch === ')') {
      depth--;
      if (row.length || cell.trim()) row.push(cell);
      cell = '';
      if (depth === 0) {
        rows.push(row);
        row = [];
      }
      continue;
    }
    if (ch === ',' && depth === 1) {
      row.push(cell);
      cell = '';
      continue;
    }
    if (depth >= 1) cell += ch;
  }
  return rows;
}

/**
 * Имена колонок из CREATE TABLE в порядке дампа.
 * Это единственный надёжный ориентир: физический порядок колонок в PostgreSQL
 * отличается (таблицы создавались несколькими `db push`, ALTER ADD COLUMN
 * ставит новую колонку в конец). Без сопоставления по именам строки
 * перемешались бы молча — типы-то во многих таблицах совместимы.
 */
function extractDumpColumns(body) {
  const names = [];
  for (const rawLine of body.split('\n')) {
    const line = rawLine.trim();
    if (/^(PRIMARY\s+KEY|KEY|UNIQUE|CONSTRAINT|CHECK|FULLTEXT|INDEX)\b/i.test(line)) continue;
    const m = line.match(/^`(\w+)`\s+/);
    if (m) names.push(m[1]);
  }
  return names;
}

/**
 * Читает дамп: Map<имя таблицы, { cols: string[], rows: string[][] }>.
 * rows[i][j] соответствует cols[j].
 */
export function readDump(file) {
  const sql = readFileSync(file, 'utf8');
  const statements = splitStatements(sql);
  const data = new Map();

  for (const st of statements) {
    const create = st.match(/^\s*CREATE\s+TABLE\s+`?(\w+)`?\s*\(([\s\S]*)\)\s*(?:ENGINE|DEFAULT CHARSET|ROW_FORMAT)/i);
    if (create) {
      data.set(create[1], { cols: extractDumpColumns(create[2]), rows: [] });
      continue;
    }

    const ins = st.match(/^\s*INSERT\s+INTO\s+`?(\w+)`?\s*(?:\([^)]*\)\s*)?VALUES?/i);
    if (!ins) continue;
    const table = ins[1];
    if (!data.has(table)) data.set(table, { cols: null, rows: [] });
    data.get(table).rows.push(...parseValuesList(st.slice(ins[0].length)));
  }
  return data;
}

// ───────────────────── соответствие типов PG ─────────────────────

/**
 * MySQL-нулевая дата ('0000-00-00 [00:00:00]') — легальное значение в дампе,
 * но PG отвергает год 0. Такие поля (birthday, date, …) осмысленно становятся NULL.
 */
function isZeroDate(s) {
  return /^0{4}-(0{2}|-0{2})/.test(s) || /^0{4}-0{2}-0{2}/.test(s);
}

/**
 * Приводит распарсенное значение дампа к типу колонки PostgreSQL.
 * Пустые строки в числовых/временных колонках трактуем как NULL: MySQL
 * допускал их при NOT NULL-дефолте '', а PG на такую вставку падает.
 */
export function coerce(value, kind, notes, where) {
  if (value === null) return null;
  const s = typeof value === 'string' ? value : String(value);

  switch (kind) {
    case 'boolean':
      if (s === '' || s === 'NULL') return null;
      return !['0', 'false', 'f'].includes(s.toLowerCase());

    case 'int':
    case 'bigint': {
      const t = s.trim().replace(/^'+|'+$/g, '');
      if (t === '') return null;
      if (!/^[+-]?\d+$/.test(t)) {
        notes.badNumbers.push(`${where}: ${JSON.stringify(s)}`);
        return null;
      }
      return t;
    }

    case 'numeric':
      if (s.trim() === '') return null;
      return s.trim();

    case 'date': {
      if (s.trim() === '') return null;
      if (isZeroDate(s.trim())) {
        notes.zeroDates.push(where);
        return null;
      }
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!m) throw new Error(`не дата: ${JSON.stringify(s)}`);
      return `${m[1]}-${m[2]}-${m[3]}`;
    }

    case 'timestamp': {
      if (s.trim() === '') return null;
      if (isZeroDate(s.trim())) {
        notes.zeroDates.push(where);
        return null;
      }
      // MySQL 'YYYY-MM-DD HH:MM:SS' PG принимает как есть; подрезаем лишнее.
      const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(\.\d+)?/);
      if (m) return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${m[6]}${m[7] ?? ''}`;
      const d = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (d) return `${d[1]}-${d[2]}-${d[3]} 00:00:00`;
      throw new Error(`не timestamp: ${JSON.stringify(s)}`);
    }

    default:
      return s; // text/varchar/json — строка из дампа уже раскодирована
  }
}

const KINDS = {
  smallint: 'int', integer: 'int', bigint: 'bigint',
  numeric: 'numeric', decimal: 'numeric',
  real: 'numeric', 'double precision': 'numeric',
  boolean: 'boolean',
  date: 'date',
  timestamp: 'timestamp', 'timestamp without time zone': 'timestamp',
  'timestamp with time zone': 'timestamp',
  time: 'text', interval: 'text',
  uuid: 'text', json: 'text', jsonb: 'text',
};

export function kindOf(dataType) {
  return KINDS[dataType] ?? 'text';
}

// ───────────────────────── метаданные схемы ─────────────────────────

async function loadColumns(client, table) {
  const { rows } = await client.query(
    `SELECT ordinal_position, column_name, data_type, character_maximum_length
       FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = $1
      ORDER BY ordinal_position`,
    [table],
  );
  return rows.map((r) => ({
    pos: Number(r.ordinal_position),
    name: r.column_name,
    kind: kindOf(r.data_type),
    dataType: r.data_type,
    limit: r.character_maximum_length,
  }));
}

/**
 * Метаданные колонок таблицы PG. Экспортируется для verify-migration.mjs.
 */
export async function loadDumpColumns(client, table) {
  return loadColumns(client, table);
}

/**
 * Топосортировка по FK из pg_catalog.
 *
 * conrelid — таблица, ДЕРЖАЩАЯ внешний ключ (ребёнок), confrelid — на которую
 * он ссылается (родитель). Ошибиться здесь особенно опасно: ограничения в PG
 * созданы NOT DEFERRABLE, поэтому `SET CONSTRAINTS ALL DEFERRED` их не откладывает —
 * обратный порядок грузится не «тихо», а с падением на первой же ссылки.
 */
async function loadOrder(client, wanted) {
  const { rows } = await client.query(
    `SELECT c.conrelid::regclass::text AS child, c.confrelid::regclass::text AS parent
       FROM pg_constraint c
       JOIN pg_namespace n ON n.oid = c.connamespace
      WHERE c.contype = 'f' AND n.nspname = 'public'`,
  );

  const deps = new Map([...wanted].map((t) => [t, new Set()]));
  for (const r of rows) {
    const child = r.child.replace(/^public\./, '');
    const parent = r.parent.replace(/^public\./, '');
    if (!deps.has(child) || !deps.has(parent)) continue;
    if (child === parent) continue; // self-FK в топосортировке не нужен
    deps.get(child).add(parent);
  }

  const order = [];
  const done = new Set();
  const visit = (t, stack) => {
    if (done.has(t)) return;
    if (stack.has(t)) throw new Error(`цикл FK: ${[...stack, t].join(' -> ')}`);
    stack.add(t);
    for (const p of deps.get(t)) visit(p, stack);
    stack.delete(t);
    done.add(t);
    order.push(t);
  };
  for (const t of [...wanted].sort()) visit(t, new Set());
  return order;
}

/**
 * Сверка составов колонок дампа и PG для ВСЕХ таблиц, а не только с данными:
 * расхождение имён означает, что схема Prisma разошлась с прод-MySQL, и это
 * надо увидеть до загрузки, а не в виде падения на конкретной таблице.
 */
function compareColumns(dumpData, pgColsByName) {
  const problems = [];
  for (const [table, entry] of dumpData) {
    const dumpNames = entry.cols;
    if (!dumpNames) continue;
    const pg = pgColsByName.get(table);
    if (!pg) continue;
    const missing = dumpNames.filter((c) => !pg.includes(c));
    const extra = pg.filter((c) => !dumpNames.includes(c));
    if (missing.length || extra.length) {
      problems.push(`${table}: в PG нет → ${missing.join(', ') || '-'}; в дампе нет → ${extra.join(', ') || '-'}`);
    }
  }
  return problems;
}

// ───────────────────────── загрузка ─────────────────────────

/**
 * Маппинг «имя колонки PG → позиция в строке дампа». Ключевое сопоставление —
 * по именам, потому что порядок колонок в дампе и в PG различается.
 */
function buildColumnMap(dumpCols, pgCols, table) {
  const mapping = {};
  for (const pgCol of pgCols) {
    const idx = dumpCols.indexOf(pgCol.name);
    if (idx === -1) {
      throw new Error(`${table}: колонка ${pgCol.name} есть в PG, но отсутствует в дампе`);
    }
    mapping[pgCol.name] = { idx };
  }
  return mapping;
}

const CASTS = {
  boolean: '::boolean', timestamp: '::timestamp', date: '::date',
  numeric: '::numeric', bigint: '::bigint', int: '::integer',
};

async function insertRows(client, table, pgCols, dumpCols, rows, notes) {
  if (!dumpCols || dumpCols.length === 0) {
    throw new Error(`${table}: не удалось извлечь имена колонок из дампа`);
  }
  const badLen = rows.filter((r) => r.length !== dumpCols.length).length;
  if (badLen) {
    throw new Error(`${table}: ${badLen} строк имеют длину ≠ ${dumpCols.length} колонок (парсер дампа ошибся)`);
  }

  const mapping = buildColumnMap(dumpCols, pgCols, table);
  const quoted = pgCols.map((c) => `"${c.name}"`).join(', ');

  // PG ограничивает количество параметров в одном запросе 65535.
  const perBatch = Math.max(1, Math.floor(60000 / pgCols.length));

  for (let i = 0; i < rows.length; i += perBatch) {
    const slice = rows.slice(i, i + perBatch);
    const params = [];
    const tuples = [];
    let p = 0;

    for (const row of slice) {
      const ph = [];
      for (const pgCol of pgCols) {
        const decoded = decodeMysqlLiteral(row[mapping[pgCol.name].idx]);
        let value;
        try {
          value = coerce(decoded, pgCol.kind, notes, `${table}.${pgCol.name}`);
        } catch (e) {
          throw new Error(`${table}.${pgCol.name}: ${e.message}`);
        }
        if (pgCol.limit && typeof value === 'string' && value.length > pgCol.limit) {
          notes.truncated.push(`${table}.${pgCol.name}: ${value.length} > ${pgCol.limit}`);
        }
        params.push(value);
        p += 1;
        // Каст нужен, потому что драйвер отправляет JS-строку в boolean/date/numeric
        // как неопределённый тип, а PG выводит его не всегда так, как мы ожидаем.
        ph.push('$' + p + (CASTS[pgCol.kind] ?? ''));
      }
      tuples.push(`(${ph.join(', ')})`);
    }

    await client.query(`INSERT INTO "${table}" (${quoted}) VALUES ${tuples.join(', ')}`, params);
  }
}

/**
 * MySQL хранил id как AUTO_INCREMENT, в PG это sequence — после явной вставки
 * id она остаётся на 1 и первый же новый INSERT упал бы на уникальности.
 */
async function resetSequences(client, table, cols) {
  for (const col of cols) {
    if (col.kind !== 'int' && col.kind !== 'bigint') continue;
    const { rows } = await client.query(
      `SELECT pg_get_serial_sequence($1, $2) AS seq`,
      [`public.${table}`, col.name],
    );
    const seq = rows[0]?.seq;
    if (!seq) continue;
    await client.query(
      `SELECT setval($1::regclass, COALESCE((SELECT MAX("${col.name}") FROM "${table}"), 1),
                      (SELECT COUNT(*) FROM "${table}") > 0)`,
      [seq],
    );
  }
}

// ───────────────────────── main ─────────────────────────

async function main() {
  const rawUrl = process.env.DATABASE_URL;
  if (!rawUrl) throw new Error('DATABASE_URL не задан (residents-v2/.env)');
  // sslmode из URL имеет приоритет над объектом ssl в pg-connection-string,
  // а Supabase держит self-signed цепочку — оставляем TLS, но без проверки CA.
  const url = rawUrl.replace(/([?&])sslmode=[^&]*(&|$)/gi, (m, sep, tail) => (sep === '?' ? '?' : tail === '&' ? '&' : ''));

  console.log(`Читаю дамп: ${DUMP}`);
  const dump = readDump(DUMP);
  let total = 0;
  let withData = 0;
  for (const { rows } of dump.values()) {
    total += rows.length;
    if (rows.length) withData++;
  }
  console.log(`Таблиц в дампе: ${dump.size}, с данными: ${withData}, строк всего: ${total}`);

  const client = new pg.Client({ connectionString: url, ssl: { rejectUnauthorized: false } });
  await client.connect();

  const { rows: existing } = await client.query(
    `SELECT table_name FROM information_schema.tables
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE'`,
  );
  const inPg = new Set(existing.map((r) => r.table_name));

  const unknown = [...dump.keys()].filter((t) => !inPg.has(t));
  if (unknown.length) {
    throw new Error(`в дампе есть таблицы, отсутствующие в PG: ${unknown.join(', ')}`);
  }

  const order = await loadOrder(client, new Set([...dump.keys(), ...inPg]));
  const notes = { truncated: [], zeroDates: [], badNumbers: [] };

  // Проверяем, что во всех таблицах имена колонок совпадают с PG
  const pgColsByName = new Map();
  for (const t of inPg) {
    const pg = await loadColumns(client, t);
    pgColsByName.set(t, pg.map((c) => c.name));
  }
  const colProblems = compareColumns(dump, pgColsByName);
  if (colProblems.length) {
    console.error('\n❌ Расхождения в именах колонок (дамп vs PG):');
    colProblems.forEach((p) => console.error('  ' + p));
    throw new Error('расхождение колонок — см. выше');
  }

  // Счётчики PG: загрузка в непустые таблицы удвоила бы строки, поэтому без
  // --truncate она невозможна, если хоть одна таблица уже содержит данные.
  const counts = new Map();
  for (const t of [...inPg].sort()) {
    const { rows } = await client.query(`SELECT COUNT(*)::int AS n FROM "${t}"`);
    counts.set(t, rows[0].n);
  }
  const nonEmpty = [...counts].filter(([, n]) => n > 0);
  if (nonEmpty.length && !DRY_RUN && !TRUNCATE) {
    throw new Error(
      `в PG уже есть данные (${nonEmpty.map(([t, n]) => `${t}=${n}`).join(', ')}). ` +
      'Повторная загрузка удвоит строки — добавьте --truncate.',
    );
  }

  if (DRY_RUN) {
    console.log('\n--dry-run: порядок загрузки и объёмы\n');
    for (const t of order) {
      const entry = dump.get(t);
      if (!entry) continue;
      const { cols: dumpCols, rows } = entry;
      const pgCols = await loadColumns(client, t);
      const pgN = counts.get(t) ?? 0;
      if (!rows.length) {
        console.log(`${t.padEnd(28)}     0 строк в дампе, ${String(pgN).padStart(5)} в PG`);
        continue;
      }
      const widths = new Set(rows.map((r) => r.length));
      const flag = dumpCols && widths.size === 1 && [...widths][0] === dumpCols.length ? '✓' : '⚠️';
      console.log(`${flag} ${t.padEnd(26)} ${String(rows.length).padStart(5)} строк, ` +
        `дамп=${[...widths].join('/')} колонок, PG=${pgCols.length}`);
      for (const c of pgCols) {
        if (c.kind !== 'boolean' && c.kind !== 'date' && c.kind !== 'numeric' && c.kind !== 'timestamp') continue;
        const idx = dumpCols ? dumpCols.indexOf(c.name) : -1;
        if (idx === -1) {
          console.log(`    · ⚠️  ${c.name} (${c.dataType}) — отсутствует в дампе`);
          continue;
        }
        const sample = [...new Set(rows.slice(0, 50).map((r) => decodeMysqlLiteral(r[idx])))].slice(0, 3);
        console.log(`    · ${c.name} (${c.dataType}) ← ${sample.map((v) => JSON.stringify(v)).join(' | ')}`);
      }
    }
    await client.end();
    return;
  }

  console.log('\nЗагружаю в транзакции...');
  await client.query('BEGIN');
  await client.query('SET CONSTRAINTS ALL DEFERRED');

  try {
    if (TRUNCATE && inPg.size) {
      const list = [...order].reverse().map((t) => `"${t}"`).join(', ');
      await client.query(`TRUNCATE TABLE ${list} RESTART IDENTITY`);
      console.log(`  ⨯ очищено таблиц: ${inPg.size}`);
    }

    for (const t of order) {
      const entry = dump.get(t);
      if (!entry) continue;
      const { cols: dumpCols, rows } = entry;
      if (!rows.length) continue;
      const pgCols = await loadColumns(client, t);
      await insertRows(client, t, pgCols, dumpCols, rows, notes);
      await resetSequences(client, t, pgCols);
      console.log(`  ✓ ${t.padEnd(26)} ${String(rows.length).padStart(5)}`);
    }
    await client.query('COMMIT');
  } catch (e) {
    await client.query('ROLLBACK');
    throw e;
  }

  // ── сверка count(*) ──
  console.log('\nСверка количества строк (PG vs дамп):');
  let bad = 0;
  for (const t of [...inPg].sort()) {
    const expected = dump.get(t)?.rows.length ?? 0;
    const { rows } = await client.query(`SELECT COUNT(*)::int AS n FROM "${t}"`);
    const actual = rows[0].n;
    const ok = actual === expected;
    if (!ok) bad++;
    console.log(`  ${ok ? '✓' : '✗'} ${t.padEnd(26)} pg=${String(actual).padStart(5)} dump=${String(expected).padStart(5)}`);
  }

  for (const [key, label] of [
    ['truncated', '⚠️  Длиннее лимита колонки'],
    ['zeroDates', '· Нулевая дата MySQL → NULL'],
    ['badNumbers', '⚠️  Не число в числовой колонке → NULL'],
  ]) {
    const list = notes[key];
    if (!list.length) continue;
    console.log(`\n${label} (${list.length}):`);
    const uniq = [...new Set(list)];
    uniq.slice(0, 20).forEach((s) => console.log('   ' + s));
    if (uniq.length > 20) console.log(`   … и ещё ${uniq.length - 20} уникальных`);
  }

  await client.end();
  console.log(bad ? `\n❌ расхождений: ${bad}` : '\n✅ данные перенесены полностью');
  process.exitCode = bad ? 1 : 0;
}

// Guard: verify-migration.mjs импортирует парсер из этого модуля, и без него
// импорт чужого файла уже выполнил бы загрузку в БД.
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => {
    console.error('\n❌ Ошибка:', e.message);
    process.exit(1);
  });
}
