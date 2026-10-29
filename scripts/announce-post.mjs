// Анонс статьи в чат жителей от @SkazKray_bot — запуск ОДНОЙ командой:
//
//   node scripts/announce-post.mjs              # анонс свежей статьи (по date)
//   node scripts/announce-post.mjs --dry-run    # показать план, ничего не шлать
//   node scripts/announce-post.mjs --slug <s>   # конкретная статья
//
// Сообщение: шапка + описание статьи + inline-кнопка «Читать статью на сайте»
// (формат согласован с правкой анонса от 24.10.2026 — менять здесь же).
//
// Как работает: токен бота и БД доступны только на сервере, поэтому скрипт
// генерирует одноразовый PHP, заливает его base64 в /tmp на abconsult, запускает
// там `php8.3` и удаляет файл за собой (паттерн разовых CLI из CLAUDE.md).
// Дубли отсекает серверная часть по журналу telegram_messages: context
// 'post-announce:<slug>' либо префикс текста (старые анонсы без этого context).

import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import matter from 'gray-matter';
import { postSlug } from '../src/lib/utils.js';

const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const POSTS_DIR = path.join(ROOT, 'src', 'content', 'posts');
const SITE = 'https://skaz-kray.ru';
const SSH_HOST = 'abconsult';
const REMOTE_PHP = '/tmp/skaz-announce-post.php';
// «Общий чат СК» — как group_chat_id в прод-конфиге; предупреждаем, если не он.
const EXPECTED_CHAT = '-1001831453321';
const BUTTON_TEXT = 'Читать статью на сайте';
const HEADER = '📰 Новая статья на сайте';   // формат анонса — как в правке от 24.10.2026

// ---------- CLI ----------

const args = process.argv.slice(2);
const opts = { slug: null, url: null, dryRun: false, force: false, help: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--dry-run') opts.dryRun = true;
  else if (a === '--force') opts.force = true;
  else if (a === '--help' || a === '-h') opts.help = true;
  else if (a === '--slug' || a === '--url') {
    const v = args[++i];
    if (!v) fail(`после ${a} нужен аргумент`);
    if (a === '--slug') opts.slug = v; else opts.url = v;
  } else fail(`неизвестный аргумент: ${a} (см. --help)`);
}

if (opts.help) {
  console.log(`Использование: node scripts/announce-post.mjs [options]

Публикует в чат «Общий чат СК» (telegram.group_chat_id прод-конфига) анонс
статьи от @SkazKray_bot: текст + inline-кнопка «Читать статью на сайте».

Options:
  --slug <slug>   анонсировать конкретную статью (слаг из URL)
  --url <url>     то же, но полный URL: https://skaz-kray.ru/<slug>/
  --dry-run       ничего не отправлять: план + проверка дублей в журнале
  --force         отправить, даже если анонс уже публиковался
  --help          эта справка

Без --slug/--url анонсируется самая свежая статья (по date во frontmatter).
Повторный запуск безопасен: уже опубликованный анонс пропускается.`);
  process.exit(0);
}

// ---------- Статьи ----------

/** "2026-10-24 12:00:00" | Date -> строка для сортировки/вывода (как в content.config.ts). */
function normalizeDate(v) {
  if (v instanceof Date && !Number.isNaN(v.getTime())) {
    const p = (n) => String(n).padStart(2, '0');
    return `${v.getUTCFullYear()}-${p(v.getUTCMonth() + 1)}-${p(v.getUTCDate())} `
      + `${p(v.getUTCHours())}:${p(v.getUTCMinutes())}:${p(v.getUTCSeconds())}`;
  }
  const s = String(v ?? '').trim();
  const m = s.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/);
  if (m) return `${m[1]} ${m[2]}`;
  const d = s.match(/^(\d{4}-\d{2}-\d{2})$/);
  return d ? `${d[1]} 00:00:00` : s;
}

function loadPosts() {
  if (!fs.existsSync(POSTS_DIR)) fail(`нет каталога ${POSTS_DIR}`);
  const names = fs.readdirSync(POSTS_DIR, { recursive: true, encoding: 'utf8' })
    .filter((f) => f.endsWith('.md'));
  return names.map((rel) => {
    const id = rel.split(/[\\/]/).join('/').replace(/\.md$/, '');
    const { data, content } = matter(fs.readFileSync(path.join(POSTS_DIR, rel), 'utf8'));
    if (!data.title) fail(`${rel}: во frontmatter нет title`);
    return {
      id,
      slug: postSlug(id),
      title: String(data.title),
      date: normalizeDate(data.date),
      seoDescription: typeof data.seoDescription === 'string' ? data.seoDescription.trim() : '',
      excerpt: typeof data.excerpt === 'string' ? data.excerpt.trim() : '',
      content,
    };
  });
}

function pickPost(posts) {
  if (opts.slug || opts.url) {
    let target = (opts.slug || '').trim().replace(/^\/+|\/+$/g, '');
    if (opts.url) {
      let u = opts.url.trim();
      if (!/^https?:\/\//i.test(u)) u = `${SITE}/${u}`;
      try {
        target = new URL(u).pathname.replace(/^\/+|\/+$/g, '');
      } catch {
        fail(`некорректный URL: «${opts.url}»`);
      }
    }
    const found = posts.find((p) => p.slug === target || p.id === target);
    if (!found) fail(`статья со слагом «${target}» не найдена в src/content/posts/`);
    return found;
  }
  const dated = posts.filter((p) => p.date).sort((a, b) => b.date.localeCompare(a.date));
  if (!dated.length) fail('не найдено ни одной статьи с date во frontmatter');
  return dated[0];
}

/** Описание для анонса: seoDescription -> excerpt -> черновик из тела. */
function descriptionOf(p) {
  if (p.seoDescription) return { text: p.seoDescription, src: 'seoDescription' };
  if (p.excerpt) return { text: p.excerpt, src: 'excerpt' };
  const plain = p.content
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/<[^>]+>/g, ' ')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/[*_~`>]+/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  if (!plain) fail(`у статьи «${p.title}» нет ни seoDescription, ни excerpt, ни текста — заполните одно из полей`);
  return {
    text: plain.length > 250 ? plain.slice(0, 250).replace(/\s+\S*$/, '') + '…' : plain,
    src: 'черновик из тела',
  };
}

// ---------- Серверная PHP-часть (генерируется, живёт только в /tmp) ----------

/** Строка в одинарных кавычках PHP: экранируем \ и ', переводы строк допустимы. */
function phpStr(s) {
  return `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function buildPhp({ text, url, context }) {
  return `<?php
declare(strict_types=1);
// Сгенерировано scripts/announce-post.mjs (одноразовый CLI, живёт в /tmp).
// Анонс статьи в общий чат жителей: дедуп по журналу -> sendMessage -> record.
require '/var/www/skaz-residents/vendor/autoload.php';
require '/var/www/skaz-residents/src/timezone.php';

use SkazResidents\\Config;
use SkazResidents\\Database;
use SkazResidents\\Env;
use SkazResidents\\Service\\TelegramOutbox;
use SkazResidents\\TelegramBot;

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

Env::load('/var/www/skaz-residents/config/.env');
Config::load('/var/www/skaz-residents/config/config.php');
Database::connect(Config::get('db'));

$tg    = Config::get('telegram');
$token = (string) ($tg['bot_token'] ?? '');
$chat  = (string) ($tg['group_chat_id'] ?? '');
$text  = ${phpStr(text)};
$url   = ${phpStr(url)};
$context = ${phpStr(context)};

if ($token === '' || $chat === '') {
    fwrite(STDERR, "ERR: telegram.bot_token/group_chat_id не заданы в конфиге\\n");
    exit(1);
}
echo "chat={$chat}\\n";
if ($chat !== ${phpStr(EXPECTED_CHAT)}) {
    echo "WARN: group_chat_id не «Общий чат СК» (" . ${phpStr(EXPECTED_CHAT)} . ") — проверьте адресата\\n";
}

// Дубль: тот же context (надёжно для будущих анонсов) либо тот же префикс
// текста (ловит анонсы, отправленные до введения context).
$st = Database::pdo()->prepare(
    'SELECT message_id, kind, context FROM telegram_messages
     WHERE chat_id = ? AND (context = ? OR LOCATE(?, text) = 1)
     ORDER BY id DESC LIMIT 1'
);
$st->execute([$chat, $context, mb_substr($text, 0, 80)]);
$dup = $st->fetch();
if ($dup !== false && !$force) {
    echo "SKIP: анонс уже публиковался (message_id={$dup['message_id']}, kind={$dup['kind']}, context={$dup['context']}); повтор — с --force\\n";
    exit(0);
}
if ($dryRun) {
    echo "DRY-RUN: проверки пройдены, отправка не выполнялась\\n";
    exit(0);
}

$markup = (string) json_encode(
    ['inline_keyboard' => [['text' => ${phpStr(BUTTON_TEXT)}, 'url' => $url]]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$id = TelegramBot::sendMessage($token, $chat, $text, null, $markup);
if ($id === null) {
    fwrite(STDERR, "ERR: sendMessage не удался (токен, сеть или бот не в чате)\\n");
    exit(1);
}
TelegramOutbox::record($chat, $id, $context, 'send', null, $text);
echo "SENT: message_id={$id}\\n";
`;
}

// ---------- Публикация ----------

function fail(msg) {
  console.error(`ОШИБКА: ${msg}`);
  process.exit(1);
}

const posts = loadPosts();
const post = pickPost(posts);
const desc = descriptionOf(post);
const text = `${HEADER}\n\n${desc.text}`;
const url = `${SITE}/${post.slug}/`;
const context = `post-announce:${post.slug}`;

if (text.length > 4000) {
  fail(`текст анонса слишком длинный (${text.length} > 4000 символов) — укоротите seoDescription`);
}

console.log('Статья:   ' + post.title);
console.log('Дата:     ' + post.date);
console.log('URL:      ' + url);
console.log('Описание: ' + desc.src);
console.log('Текст анонса:');
console.log('---');
console.log(text);
console.log('---');
console.log(`Режим:    ${opts.dryRun ? 'dry-run (без отправки)' : 'боевой'}${opts.force ? ' + force' : ''}`);
console.log(`SSH:      ${SSH_HOST} (${REMOTE_PHP}, удалится после запуска)`);
console.log('');

const php = buildPhp({ text, url, context });
const b64 = Buffer.from(php, 'utf8').toString('base64');
const flags = [opts.dryRun && '--dry-run', opts.force && '--force'].filter(Boolean).join(' ');
const remoteCmd = `echo '${b64}' | base64 -d > ${REMOTE_PHP}`
  + ` && php8.3 ${REMOTE_PHP}${flags ? ' ' + flags : ''}; rc=$?; rm -f ${REMOTE_PHP}; exit $rc`;

const res = spawnSync('ssh', [SSH_HOST, remoteCmd], { encoding: 'utf8', timeout: 60000 });
if (res.error) fail(`не удалось запустить ssh: ${res.error.message}`);
if (res.stdout) process.stdout.write(res.stdout);
if (res.stderr) process.stderr.write(res.stderr);
if (res.status !== 0) {
  fail(`серверная часть завершилась с кодом ${res.status}${res.signal ? ` (сигнал ${res.signal})` : ''}`);
}
process.exit(0);



