<?php
declare(strict_types=1);
namespace SkazResidents;

/**
 * Загрузка фото в приватный Telegram-канал «Skaz-Kray Media» (тот же, что у
 * новостей блога) через Bot API sendPhoto. Возвращает file_id самой большой
 * копии — фото затем публично отдаётся через /tg-media/<file_id>.jpg (serve.php
 * кэширует байты на диск). Конфиг — Config::get('tg_media') = ['bot_token', 'chat_id'].
 * При недоступности/ошибке возвращает null (вызывающий делает локальный фолбэк).
 *
 * Сознательно sendPhoto (Telegram пережимает до ~1280px) — легче и быстрее отдаётся.
 */
final class TelegramMedia
{
    /** @param array{tmp_name?:string,error?:int} $file  @return string|null file_id либо null */
    public static function upload(array $file): ?string
    {
        $cfg = Config::get('tg_media');
        if (!is_array($cfg) || empty($cfg['bot_token']) || empty($cfg['chat_id'])) { return null; }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { return null; }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $info = $tmp !== '' ? @getimagesize($tmp) : false;
        if ($info === false || !Validator::imageMime($info['mime'])) { return null; }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? 'jpg';

        $ch = curl_init('https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendPhoto');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'chat_id' => $cfg['chat_id'],
                'photo'   => new \CURLFile($tmp, $info['mime'], 'photo.' . $ext),
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = $raw !== false ? json_decode((string) $raw, true) : null;
        if ($code !== 200 || !is_array($data) || empty($data['ok'])) { return null; }
        $photos = $data['result']['photo'] ?? [];
        if (!$photos) { return null; }
        $best = end($photos);
        return isset($best['file_id']) ? (string) $best['file_id'] : null;
    }
}
