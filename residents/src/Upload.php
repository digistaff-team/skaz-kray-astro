<?php
declare(strict_types=1);
namespace SkazResidents;

final class Upload
{
    private const MAX_BYTES = 5_242_880; // 5 МБ
    private const MAX_DIM   = 1600;      // px по большей стороне

    /**
     * Валидирует и пересохраняет одно изображение из $_FILES-записи.
     * Возвращает имя файла (относительно uploads_dir) либо null с текстом ошибки.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{0:?string,1:?string} [filename, error]
     */
    public static function saveImage(array $file, string $uploadsDir): array
    {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return [null, null]; // файл не приложен — не ошибка
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'Ошибка загрузки файла.'];
        }
        if ($file['size'] > self::MAX_BYTES) {
            return [null, 'Файл больше 5 МБ.'];
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return [null, 'Файл не является изображением.'];
        }
        $mime = $info['mime'];
        if (!Validator::imageMime($mime)) {
            return [null, 'Допустимы только JPEG, PNG и WebP.'];
        }

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
        };
        if ($src === false) {
            return [null, 'Не удалось прочитать изображение.'];
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // bin2hex(random_bytes) — уникальное имя; сохраняем в JPEG (EXIF снимается пересохранением)
        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        imagejpeg($dst, $uploadsDir . '/' . $name, 85);
        imagedestroy($src);
        imagedestroy($dst);

        return [$name, null];
    }
}
