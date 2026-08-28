<?php
declare(strict_types=1);
namespace SkazResidents;

final class Validator
{
    public static function email(string $v): bool
    {
        return (bool) filter_var(trim($v), FILTER_VALIDATE_EMAIL);
    }

    public static function required(string $v): bool
    {
        return trim($v) !== '';
    }

    public static function length(string $v, int $min, int $max): bool
    {
        $n = mb_strlen(trim($v));
        return $n >= $min && $n <= $max;
    }

    public static function password(string $v): bool
    {
        return mb_strlen($v) >= 8;
    }

    public static function imageMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }
}
