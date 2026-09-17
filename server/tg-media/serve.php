<?php
declare(strict_types=1);

// Публичная раздача фото, хранящихся в приватном Telegram-канале
// «Skaz-Kray Media» (chat_id в config.php). Первый запрос на конкретный
// file_id тянет байты через Bot API (getFile + скачивание) и кладёт их
// рядом с собой на диск — все следующие запросы отдаёт nginx как обычную
// статику (см. location ^~ /tg-media/ в конфиге сайта), без похода в
// Telegram и без PHP. Токен бота наружу никогда не уходит.

$config = require __DIR__ . '/config.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{10,150}$/', $id)) {
    http_response_code(400);
    exit;
}

// Ширина миниатюры: только из белого списка — чтобы никто не мог заказать
// произвольный ресайз и засыпать диск вариантами одного фото. Без w отдаём
// оригинал, как раньше.
const THUMB_WIDTHS = [120, 240, 480];
$w = isset($_GET['w']) ? (int) $_GET['w'] : 0;
if ($w !== 0 && !in_array($w, THUMB_WIDTHS, true)) {
    http_response_code(400);
    exit;
}

$cachePath = __DIR__ . '/cache/' . $id . '.jpg';

if ($w !== 0) {
    // Миниатюра лежит своим файлом (cache/w240/<id>.jpg) — nginx отдаёт её
    // статикой, сюда запрос попадает только в первый раз.
    $thumbDir  = __DIR__ . '/cache/w' . $w;
    $thumbPath = $thumbDir . '/' . $id . '.jpg';
    if (!is_file($thumbPath)) {
        if (!is_file($cachePath) && !fetchOriginal($config['bot_token'], $id, $cachePath)) {
            http_response_code(502);
            exit;
        }
        if (!is_dir($thumbDir)) { @mkdir($thumbDir, 0755, true); }
        if (!makeThumb($cachePath, $thumbPath, $w)) {
            $thumbPath = $cachePath;   // GD не справился — отдаём оригинал
        }
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($thumbPath));
    readfile($thumbPath);
    exit;
}

if (!is_file($cachePath)) {
    $meta = tgRequest($config['bot_token'], 'getFile', ['file_id' => $id]);
    $filePath = $meta['result']['file_path'] ?? null;
    if ($filePath === null) {
        http_response_code(404);
        exit;
    }

    $bytes = httpGet('https://api.telegram.org/file/bot' . $config['bot_token'] . '/' . $filePath);
    if ($bytes === null) {
        http_response_code(502);
        exit;
    }

    // Пишем во временный файл и переименовываем — исключает раздачу
    // недокачанного файла при параллельных запросах на тот же id.
    $tmp = $cachePath . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $bytes);
    rename($tmp, $cachePath);
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($cachePath));
readfile($cachePath);
exit;

/** Скачать оригинал из Telegram в дисковый кеш. true — файл на месте. */
function fetchOriginal(string $token, string $id, string $cachePath): bool
{
    $meta = tgRequest($token, 'getFile', ['file_id' => $id]);
    $filePath = $meta['result']['file_path'] ?? null;
    if ($filePath === null) { return false; }

    $bytes = httpGet('https://api.telegram.org/file/bot' . $token . '/' . $filePath);
    if ($bytes === null) { return false; }

    $tmp = $cachePath . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $bytes);
    rename($tmp, $cachePath);
    return true;
}

/**
 * Уменьшенная копия по ширине (высота пропорциональна, увеличение не делаем).
 * Пишем во временный файл и переименовываем — при параллельных запросах на тот
 * же размер никто не увидит недописанный JPEG.
 */
function makeThumb(string $src, string $dst, int $width): bool
{
    if (!function_exists('imagecreatefromjpeg')) { return false; }
    $img = @imagecreatefromjpeg($src);
    if ($img === false) { return false; }

    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $width / max(1, $w));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = $dst . '.' . getmypid() . '.tmp';
    $ok = imagejpeg($out, $tmp, 82);
    imagedestroy($img);
    imagedestroy($out);
    if (!$ok) { @unlink($tmp); return false; }
    rename($tmp, $dst);
    return true;
}

function tgRequest(string $token, string $method, array $params): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method . '?' . http_build_query($params);
    $raw = httpGet($url);
    $data = $raw !== null ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function httpGet(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res === false || $code !== 200) ? null : (string) $res;
}
