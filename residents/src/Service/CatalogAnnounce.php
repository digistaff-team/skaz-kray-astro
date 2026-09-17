<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, TelegramBot, MaxBot};

/**
 * Анонс новинок каталогов (инструменты, книги) в группы жителей от @SkazKray_bot:
 * одно короткое сообщение в Telegram-группу (telegram.group_chat_id) и в группу
 * MAX (max.group_chat_id), если она настроена. Это та же группа, членство в
 * которой открывает вход в портал, — то есть анонс видят все жители, и ничья
 * личка не засоряется.
 *
 * Ссылка в анонсе — диплинк мини-приложения: портал закрыт, а вход через
 * Mini App логинит жителя автоматически (см. TgAuthController::decodeStartParam,
 * путь ограничен префиксом /poselenie/).
 *
 * Отправка идёт ПОСЛЕ ответа пользователю (fastcgi_finish_request), поэтому
 * недоступность api.telegram.org не задерживает добавление карточки и не роняет
 * его: все ошибки только в error_log.
 */
final class CatalogAnnounce
{
    /** Анонс нового инструмента. */
    public static function tool(int $id, string $name, ?string $category): void
    {
        $head = '🔧 Новый инструмент в общей копилке';
        $line = '«' . $name . '»' . (($category ?? '') !== '' ? ' · ' . $category : '');
        self::announce($head, $line, '/poselenie/instrumenty/' . $id);
    }

    /** Анонс новой книги. */
    public static function book(int $id, string $title, ?string $author): void
    {
        $head = '📖 Новая книга на общей полке';
        $line = '«' . $title . '»' . (($author ?? '') !== '' ? ' · ' . $author : '');
        self::announce($head, $line, '/poselenie/knigi/' . $id);
    }

    /** Текст сообщения: заголовок, карточка, ссылка. */
    public static function text(string $head, string $line, string $link): string
    {
        return implode("\n", [$head, '', $line, '', 'Открыть: ' . $link]);
    }

    /**
     * Диплинк мини-приложения на страницу портала. $base — ссылка бота
     * («…/app» в Telegram, «…?startapp» в MAX), $path — путь внутри /poselenie/.
     */
    public static function deepLink(string $base, string $path): string
    {
        $code = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
        return str_ends_with($base, '?startapp')
            ? $base . '=' . $code                 // MAX: параметр уже в ссылке
            : rtrim($base, '/') . '?startapp=' . $code;
    }

    private static function announce(string $head, string $line, string $path): void
    {
        $tg     = Config::get('telegram');
        $max    = Config::get('max');
        $token  = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
        $chat   = is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '';
        $mToken = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        $mChat  = is_array($max) ? (string) ($max['group_chat_id'] ?? '') : '';
        $tgBase = (string) (Config::get('residents_app_link', 'https://t.me/SkazKray_bot/app') ?: 'https://t.me/SkazKray_bot/app');
        $mBase  = is_array($max) ? (string) ($max['app_link'] ?? '') : '';

        self::afterResponse(static function () use ($head, $line, $path, $token, $chat, $mToken, $mChat, $tgBase, $mBase): void {
            if ($token !== '' && $chat !== '') {
                if (!TelegramBot::sendMessage($token, $chat, self::text($head, $line, self::deepLink($tgBase, $path)))) {
                    error_log('CatalogAnnounce: анонс не ушёл в Telegram-группу ' . $chat);
                }
            }
            if ($mToken !== '' && $mChat !== '' && $mBase !== '') {
                if (!MaxBot::sendToChat($mToken, $mChat, self::text($head, $line, self::deepLink($mBase, $path)))) {
                    error_log('CatalogAnnounce: анонс не ушёл в группу MAX ' . $mChat);
                }
            }
        });
    }

    /**
     * Выполнить работу после того, как ответ ушёл пользователю. Сессию закрываем
     * первой: иначе следующий запрос жителя (редирект на карточку) ждал бы снятия
     * блокировки файла сессии всё время рассылки.
     */
    private static function afterResponse(callable $fn): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        ignore_user_abort(true);
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('CatalogAnnounce: ' . $e->getMessage());
        }
    }
}
