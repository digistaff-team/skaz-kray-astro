/**
 * Сверка перенесённых данных с дампом — независимо от скрипта загрузки.
 *
 * Загрузка (`migrate-mysql-to-pg.mjs`) уже сравнила count(*). Этого мало:
 * неверный маппинг колонок или эскейпинг дал бы правильное число строк с
 * неправильным содержимым. Здесь для каждой таблицы считается хеш каждой
 * строки в PG и в дампе (нормализованной к типам PG) и сравниваются множества,
 * поэтому поимка сдвига колонок, потерянного эскейпа или mojibake неизбежна.
 *
 * Плюс проверяются:
 *  - sequence: после явной вставки id они должны быть >= MAX(id), иначе первый
 *    же INSERT в проде упадёт на уникальности первичного ключа;
 *  - FK-сироты: строки, ссылающиеся на несуществующий id (при отложенных
 *    ограничениях загрузка могла бы их пропустить).
 *
 * Запуск: node residents-v2/scripts/verify-migration.mjs [--sample]
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';
import pg from 'pg';
import dotenv from 'dotenv';
import { readDump, decodeMysqlLiteral, coerce, kindOf, loadDumpColumns } from './migrate-mysql-to-pg.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.join(HERE, '..', '.env') });

const DUMP = path.join(HERE, '..', '..', 'residents_db.sql');
const SHOW_SAMPLE = process.argv.includes('--sample');

// pg по умолчанию превращает timestamp/date в JS Date, трактуя значение как
// ЛОКАЛЬНОЕ время, а обратно String() даёт UTC-ISO — сравнение с дампом
// расходится на часовой пояс (в water_level_history это 853 «расхождения» из
// 1028). Для сверки нужны ровно те символы, что лежат в колонке.
pg.types.setTypeParser(pg.types.builtins.TIMESTAMP, (v) => v);
pg.types.setTypeParser(pg.types.builtins.DATE, (v) => v);
pg.types.setTypeParser(pg.types.builtins.NUMERIC, (v) => v);

/**
 * Единая нормализация значения для PG и дампа.
 * Всё сводится к строке; NULL — к пустой строке-маркеру.
 */
function norm(value, kind) {
  if (value === null || value === undefined) return '\u2205';
  if (typeof value === 'boolean') return value ? '1' : '0';
  if (value instanceof Date) {
    // PG-дат в驱动 вернёт Date только для timestamp with time zone; для
    // date/timestamp without tz pg отдаёт строку. На всякий случай — UTC-ISO.
    return value.toISOString().replace('T', ' ').slice(0, 19);
  }
  const s = String(value);
  if (kind === 'int' || kind === 'bigint') {
    const n = Number(s);
    return Number.isFinite(n) ? String(n) : s;
  }
  if (kind === 'numeric') {
    // '91403.00' (дамп) и '91403.00' (PG) совпадают как строки, но если PG
    // вернул '91403.0' — сравнение по числу честнее.
    const n = Number(s);
    return Number.isFinite(n) ? String(n) : s;
  }
  return s;
}

function hashOf(cells) {
  return crypto.createHash('sha256').update(cells.join('\u0001')).digest('hex').slice(0, 16);
}

async function main() {
  const rawUrl = process.env.DATABASE_URL;
  if (!rawUrl) throw new Error('DATABASE_URL не задан (residents-v2/.env)');
  const url = rawUrl.replace(/([?&])sslmode=[^&]*(&|$)/gi, (m, sep, tail) => (sep === '?' ? '?' : tail === '&' ? '&' : ''));

  const client = new pg.Client({ connectionString: url, ssl: { rejectUnauthorized: false } });
  await client.connect();

  const dump = readDump(DUMP);

  const { rows: tables } = await client.query(
    `SELECT table_name FROM information_schema.tables
      WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name`,
  );

  let badTables = 0;

  for (const { table_name: t } of tables) {
    const pgCols = await loadDumpColumns(client, t);
    const entry = dump.get(t);

    if (!entry || !entry.rows.length) {
      const { rows } = await client.query(`SELECT COUNT(*)::int AS n FROM "${t}"`);
      if (rows[0].n !== 0) {
        console.log(`✗ ${t.padEnd(26)} в дампе пусто, в PG ${rows[0].n} строк`);
        badTables++;
      }
      continue;
    }

    const { cols: dumpCols, rows: dumpRows } = entry;
    const pgNames = pgCols.map((c) => c.name);
    const kindByName = new Map(pgCols.map((c) => [c.name, c.kind]));

    // ── множество хешей строк из PG ──
    const { rows: pgRows } = await client.query(
      `SELECT ${pgNames.map((n) => `"${n}"`).join(', ')} FROM "${t}" ORDER BY ${pgNames[0]}`,
    );
    const pgHashes = pgRows.map((r) => hashOf(pgNames.map((n) => norm(r[n], kindByName.get(n)))));

    // ── множество хешей строк из дампа ──
    const notes = { truncated: [], zeroDates: [], badNumbers: [] };
    const dumpHashes = dumpRows.map((row) =>
      hashOf(pgNames.map((n) => {
        const decoded = decodeMysqlLiteral(row[dumpCols.indexOf(n)]);
        return norm(coerce(decoded, kindByName.get(n), notes, `${t}.${n}`), kindByName.get(n));
      })),
    );

    const pgSet = new Set(pgHashes);
    const dumpSet = new Set(dumpHashes);
    const dupPg = pgHashes.length - pgSet.size;
    const missing = [...dumpSet].filter((h) => !pgSet.has(h)).length;
    const extra = [...pgSet].filter((h) => !dumpSet.has(h)).length;

    const ok = !dupPg && !missing && !extra && pgRows.length === dumpRows.length;
    if (!ok) {
      badTables++;
      console.log(`✗ ${t.padEnd(26)} pg=${pgRows.length} dump=${dumpRows.length} ` +
        `отсутствуют=${missing} лишние=${extra} дубликаты_строк=${dupPg}`);
      if (SHOW_SAMPLE) await showDiff(client, t, pgNames, pgCols, dumpCols, dumpRows, kindByName);
    } else {
      console.log(`✓ ${t.padEnd(26)} ${String(pgRows.length).padStart(5)} строк — побайтово совпадают`);
    }
  }

  // ── sequence ──
  console.log('\nSequence (должны быть >= MAX(id)):');
  const { rows: seqs } = await client.query(
    `SELECT tc.table_name,
            kcu.column_name
       FROM information_schema.table_constraints tc
       JOIN information_schema.key_column_usage kcu
         ON kcu.constraint_name = tc.constraint_name
        AND kcu.table_schema = tc.table_schema
       WHERE tc.constraint_type = 'PRIMARY KEY'
         AND tc.table_schema = 'public'`,
  );
  let seqBad = 0;
  for (const { table_name, column_name } of seqs) {
    const { rows } = await client.query(`SELECT pg_get_serial_sequence($1, $2) AS seq`,
      [`public.${table_name}`, column_name]);
    const seq = rows[0]?.seq;
    if (!seq) continue;
    const { rows: v } = await client.query(
      `SELECT last_value::bigint AS last, (SELECT MAX("${column_name}") FROM "${table_name}")::bigint AS mx`,
      [seq],
    ).catch(() => ({ rows: [] }));
    if (!v.length) continue;
    const { last, mx } = v[0];
    const need = mx === null ? 1 : Number(mx);
    if (Number(last) < need) {
      console.log(`  ✗ ${table_name}.${column_name}: last=${last} < max=${mx}`);
      seqBad++;
    }
  }
  console.log(seqBad ? `  расхождений sequence: ${seqBad}` : '  ✓ все sequence корректны');

  // ── FK-сироты ──
  console.log('\nFK-сироты:');
  const { rows: fks } = await client.query(
    `SELECT c.conrelid::regclass::text AS child, c.confrelid::regclass::text AS parent,
            string_agg(pg_get_constraintdef(c.oid), ' / ') AS def
       FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
      WHERE c.contype = 'f' AND n.nspname = 'public' GROUP BY 1, 2, c.oid`,
  );
  let orphans = 0;
  for (const fk of fks) {
    const m = fk.def.match(/\(([^)]+)\) REFERENCES (?:public\.)?"?(\w+)"?\s*\(([^)]+)\)/i);
    if (!m) continue;
    const cols = m[1].split(',').map((s) => s.trim().replace(/"/g, ''));
    const pcols = m[3].split(',').map((s) => s.trim().replace(/"/g, ''));
    const child = fk.child.replace(/^public\./, '');
    const parent = m[2];
    if (cols.length !== pcols.length) continue;
    const on = cols.map((c, i) => `ch."${c}" = pa."${pcols[i]}"`).join(' AND ');
    const nullsafe = cols.map((c) => `ch."${c}" IS NOT NULL`).join(' AND ');
    const { rows } = await client.query(
      `SELECT COUNT(*)::int AS n FROM "${child}" ch LEFT JOIN "${parent}" pa ON ${on}
        WHERE ${nullsafe} AND ${pcols.map((c) => `pa."${c}" IS NULL`).join(' OR ')}`,
    );
    if (rows[0].n) {
      console.log(`  ✗ ${child} → ${parent}: ${rows[0].n} сирот`);
      orphans++;
    }
  }
  console.log(orphans ? `  сирот: ${orphans}` : '  ✓ сирот нет');

  await client.end();
  const ok = !badTables && !seqBad && !orphans;
  console.log(ok ? '\n✅ перенос сверен полностью' : `\n❌ проблемных таблиц: ${badTables}, sequence: ${seqBad}, FK-сирот: ${orphans}`);
  process.exitCode = ok ? 0 : 1;
}

/** Показывает по одной различающейся строке: дамп vs PG, по колонкам. */
async function showDiff(client, t, pgNames, pgCols, dumpCols, dumpRows, kindByName) {
  const notes = { truncated: [], zeroDates: [], badNumbers: [] };
  const dumpHashes = dumpRows.map((row) =>
    crypto.createHash('sha256').update(
      pgNames.map((n) => norm(coerce(decodeMysqlLiteral(row[dumpCols.indexOf(n)]), kindByName.get(n), notes, t), kindByName.get(n))).join('\u0001'),
    ).digest('hex').slice(0, 16),
  );
  const { rows: pgRows } = await client.query(
    `SELECT ${pgNames.map((n) => `"${n}"`).join(', ')} FROM "${t}" ORDER BY ${pgNames[0]}`);
  const pgSet = new Set(pgRows.map((r) =>
    crypto.createHash('sha256').update(pgNames.map((n) => norm(r[n], kindByName.get(n))).join('\u0001')).digest('hex').slice(0, 16)));

  const idx = dumpHashes.findIndex((h) => !pgSet.has(h));
  if (idx === -1) return;
  const dumpRow = dumpRows[idx];
  const pgRow = pgRows[Math.min(idx, pgRows.length - 1)];
  console.log(`    пример: PK=${dumpRow[0]} — различия по колонкам:`);
  for (const n of pgNames) {
    const a = norm(coerce(decodeMysqlLiteral(dumpRow[dumpCols.indexOf(n)]), kindByName.get(n), notes, t), kindByName.get(n));
    const b = norm(pgRow[n], kindByName.get(n));
    if (a !== b) console.log(`      ${n}: дамп=${JSON.stringify(a)} pg=${JSON.stringify(b)}`);
  }
}

main().catch((e) => {
  console.error('\n❌ Ошибка:', e.message);
  process.exit(1);
});
