<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SkazResidents\Config;
use SkazResidents\Database;
use SkazResidents\Env;

// Секреты из окружения (config/.env, вне git) — config.php читает их через getenv().
Env::load(__DIR__ . '/../config/.env');
Config::load(__DIR__ . '/../config/config.php');

$session = Config::get('session_name', 'skazres');
session_name($session);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function status_label(string $status): string
{
    return match ($status) {
        'pending'   => 'на проверке',
        'published' => 'опубликовано',
        'rejected'  => 'отклонено',
        default     => $status,
    };
}

/**
 * "2026-08-29 10:00:00" -> "29 августа 2026". Формат идентичен ruDate()
 * из src/lib/utils.js (внешний Astro-сайт) — для визуального единообразия
 * публичных страниц раздела жителей с остальным сайтом.
 */
function ru_date(?string $s): string
{
    if (!$s) { return ''; }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) { return $s; }
    static $months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    return ((int) $m[3]) . ' ' . $months[((int) $m[2]) - 1] . ' ' . $m[1];
}

/** Подпись стадии закупки для интерфейса. */
function buy_status_label(string $status): string
{
    return match ($status) {
        'collecting' => 'идёт сбор',
        'ordered'    => 'заказано',
        'arrived'    => 'привезли',
        'done'       => 'завершена',
        'cancelled'  => 'отменена',
        default      => $status,
    };
}

/** Класс-цвет стадии закупки (переиспользуем палитру статусов инструментов). */
function buy_status_class(string $status): string
{
    return 'tool-st--' . match ($status) {
        'collecting' => 'free',
        'ordered'    => 'loan',
        'arrived'    => 'maint',
        default      => 'hidden',
    };
}

/** «450.00» → «450», «2.50» → «2.5»: хвост нулей в объёмах и ценах только мешает. */
function buy_qty(string $value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

/** Цена с разделителем тысяч и без лишних нулей: «12 500», «450.5». */
function buy_money(string $value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ' '), '0'), '.');
}

/** Русское склонение: plural_ru(2, 'задача', 'задачи', 'задач'). */
function plural_ru(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) { return $many; }
    if ($n1 > 1 && $n1 < 5) { return $few; }
    if ($n1 === 1) { return $one; }
    return $many;
}

/** Строка статуса дневника для мобильного лаунчера. $d = ['latestStatus', ...]. */
function diary_status_line(array $d): string
{
    $map = ['pending' => 'на проверке', 'published' => 'опубликована', 'rejected' => 'отклонена'];
    $st = $map[$d['latestStatus'] ?? ''] ?? 'в дневнике';
    return 'Ваш дневник: последняя запись ' . $st;
}

/**
 * Версия статик-ассета по времени изменения файла — для кэш-бастинга
 * (nginx отдаёт /poselenie/assets/* с 30-дневным кэшем; без ?v= обновления
 * стилей не доходят до вернувшихся посетителей). $rel — путь от public/.
 */
function asset_ver(string $rel): string
{
    $m = @filemtime(__DIR__ . '/../public/' . ltrim($rel, '/'));
    return $m ? (string) $m : '1';
}

/** Грубое определение мобильного браузера по User-Agent (для выбора посадочной). */
function is_mobile_ua(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua);
}

/**
 * URL картинки записи: фото в Telegram-канале хранятся как «tg:<file_id>» и
 * отдаются через /tg-media/<file_id>.jpg; локальные — из uploads_url.
 */
function entry_image_url(string $path): string
{
    if (str_starts_with($path, 'tg:')) {
        return '/tg-media/' . substr($path, 3) . '.jpg';
    }
    return rtrim((string) \SkazResidents\Config::get('uploads_url'), '/') . '/' . $path;
}

/**
 * Контакты из свободного текста («+7 (988) 242-57-63, @nadinLeto») — кликабельными:
 * телефон открывает звонилку смартфона (tel:), @ник — чат в Telegram. Текст
 * экранируем здесь же, поэтому в шаблоне выводится без View::e.
 */
/** Телефон в любом привычном виде: +7 (988) 242-57-63, 8 988 242-57-63, 89882425763. */
const CONTACT_PHONE_RE = '~(?<![\d@])\+?\d[\d\s().-]{8,}\d~u';
/** @ник Telegram: 5–32 буквы/цифры/подчёркивания, не внутри почты и не в ссылке. */
const CONTACT_TELEGRAM_RE = '~(?<![\w/])@([A-Za-z\d_]{5,32})\b~u';

function contact_links(string $text): string
{
    $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $html = (string) preg_replace_callback(CONTACT_PHONE_RE, static function (array $m): string {
        $tel = contact_tel_href($m[0]);
        return $tel === null ? $m[0] : '<a href="tel:' . $tel . '">' . $m[0] . '</a>';
    }, $html);
    // js-tg-link нужен внутри мини-приложения: там обычная ссылка t.me в webview
    // не открывается (см. partials/tg-links.php).
    return (string) preg_replace(
        CONTACT_TELEGRAM_RE,
        '<a class="js-tg-link" href="https://t.me/$1" target="_blank" rel="noopener">@$1</a>',
        $html
    );
}

/** Найденный кусок текста → значение для tel: либо null, если цифр слишком мало для номера. */
function contact_tel_href(string $raw): ?string
{
    $digits = (string) preg_replace('~\D~', '', $raw);
    if (strlen($digits) < 10) { return null; }
    return (str_starts_with($raw, '+') ? '+' : '') . $digits;
}

/** Первый телефон из контакта — для кнопки «Позвонить». */
function contact_phone(string $text): ?string
{
    if (!preg_match_all(CONTACT_PHONE_RE, $text, $m)) { return null; }
    foreach ($m[0] as $raw) {
        $tel = contact_tel_href($raw);
        if ($tel !== null) { return $tel; }
    }
    return null;
}

/** Первый @ник Telegram из контакта (без «@») — для кнопки «Написать». */
function contact_telegram(string $text): ?string
{
    return preg_match(CONTACT_TELEGRAM_RE, $text, $m) ? $m[1] : null;
}

/**
 * Цена товара Ярмарки для показа: «500 ₽ за кг.», «500 ₽» или «по договорённости».
 * Цена — свободный текст, единица без цены смысла не имеет и не показывается.
 */
function product_price_label(?string $price, ?string $unit = null): string
{
    $price = trim((string) $price);
    $unit  = trim((string) $unit);
    if ($price === '') { return 'Цена: по договорённости'; }
    // С появлением числового поля цена — это рубли; у товаров до этого в price лежит
    // свободный текст («500 ₽ за банку») — его показываем как есть.
    if (preg_match('/^\d+(\.\d+)?$/', $price)) { $price = buy_money($price) . ' ₽'; }
    return $unit === '' ? $price : $price . ' за ' . $unit;
}

/**
 * Уменьшенная копия фото для миниатюр: /tg-media/w<W>/<file_id>.jpg.
 * Оригиналы из Telegram весят 300–500 КБ, и тянуть их ради иконки 84×84 — это
 * мегабайты трафика на страницу (так тормозил справочник «Наши соседи»).
 * Ширина — только из белого списка serve.php; локальные файлы отдаём как есть.
 */
function entry_image_thumb(string $path, int $width = 240): string
{
    if (str_starts_with($path, 'tg:') && in_array($width, [120, 240, 480], true)) {
        return '/tg-media/w' . $width . '/' . substr($path, 3) . '.jpg';
    }
    return entry_image_url($path);
}

Database::connect(Config::get('db'));
