<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, TelegramBot, MaxBot};
use SkazResidents\Repository\FamilyRepository;

/**
 * Общая механика отправки сообщений от @SkazKray_bot из веб-запроса: диплинки
 * мини-приложения, отправка в группу жителей и лично семье, и — главное —
 * выполнение всего этого ПОСЛЕ ответа пользователю.
 *
 * Пользуются: {@see CatalogAnnounce} (анонсы новинок в группу) и
 * {@see LoanNotify} (личные уведомления участникам проката).
 */
final class BotNotify
{
    /**
     * Выполнить работу после того, как ответ ушёл пользователю. Сессию закрываем
     * первой: иначе следующий запрос жителя (редирект после формы) ждал бы снятия
     * блокировки файла сессии всё время отправки. Ошибки — только в лог: почта и
     * сама операция в БД уже прошли, ронять их из-за бота нельзя.
     */
    public static function afterResponse(callable $fn): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        ignore_user_abort(true);
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('BotNotify: ' . $e->getMessage());
        }
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

    /** База диплинка мини-приложения жителей: Telegram и MAX. */
    public static function appBase(string $platform): string
    {
        if ($platform === 'max') {
            $max = Config::get('max');
            return is_array($max) ? (string) ($max['app_link'] ?? '') : '';
        }
        return (string) (Config::get('residents_app_link', 'https://t.me/SkazKray_bot/app') ?: 'https://t.me/SkazKray_bot/app');
    }

    /**
     * Сообщение в группу жителей (Telegram + MAX, если группа настроена).
     * $text(string $link) получает диплинк своей платформы.
     */
    public static function toGroup(callable $text): void
    {
        $tg  = Config::get('telegram');
        $max = Config::get('max');
        $token  = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
        $chat   = is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '';
        $mToken = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        $mChat  = is_array($max) ? (string) ($max['group_chat_id'] ?? '') : '';
        $tgBase = self::appBase('tg');
        $mBase  = self::appBase('max');

        if ($token !== '' && $chat !== '' && !TelegramBot::sendMessage($token, $chat, $text($tgBase))) {
            error_log('BotNotify: сообщение не ушло в Telegram-группу ' . $chat);
        }
        if ($mToken !== '' && $mChat !== '' && $mBase !== '' && !MaxBot::sendToChat($mToken, $mChat, $text($mBase))) {
            error_log('BotNotify: сообщение не ушло в группу MAX ' . $mChat);
        }
    }

    /**
     * Личное сообщение семье — в Telegram и/или MAX, смотря что привязано.
     * Бот может писать только тем, кто сам открывал с ним диалог: если житель
     * этого не делал, Telegram ответит ошибкой — пишем её в лог и живём дальше
     * (у всех этих событий есть дублирующее письмо на email).
     */
    public static function toFamily(int $familyId, callable $text): void
    {
        $family = (new FamilyRepository())->findById($familyId);
        if (!$family) { return; }

        $tg     = Config::get('telegram');
        $max    = Config::get('max');
        $token  = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
        $mToken = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        $tgId   = (string) ($family['telegram_id'] ?? '');
        $maxId  = (string) ($family['max_user_id'] ?? '');

        if ($token !== '' && $tgId !== '' && !TelegramBot::sendMessage($token, $tgId, $text(self::appBase('tg')))) {
            error_log('BotNotify: личное сообщение не ушло в Telegram семье ' . $familyId);
        }
        $mBase = self::appBase('max');
        if ($mToken !== '' && $maxId !== '' && $mBase !== '' && !MaxBot::sendMessage($mToken, $maxId, $text($mBase))) {
            error_log('BotNotify: личное сообщение не ушло в MAX семье ' . $familyId);
        }
    }
}
