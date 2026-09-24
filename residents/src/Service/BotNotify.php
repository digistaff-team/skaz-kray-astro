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
 * Пользуются: {@see CatalogAnnounce} (анонсы новинок в группу) и личные
 * уведомления разделов через {@see personal()} — книги ({@see LoanNotify}),
 * инструменты, поездки, модерация.
 */
final class BotNotify
{
    /** Отправка после ответа пользователю — механика в {@see AfterResponse}. */
    public static function afterResponse(callable $fn): void
    {
        AfterResponse::run($fn, 'BotNotify');
    }

    /**
     * Личное уведомление семье: строки текста и ссылка в приложение.
     *
     * Внимание к порядку: {@see AfterResponse} не откладывает работу, а тут же
     * завершает ответ и закрывает сессию. Звать ТОЛЬКО после Flash::set() и
     * header('Location: …'), иначе редирект и сообщение до браузера не дойдут.
     *
     * @param array<int,string> $lines
     */
    public static function personal(int $familyId, array $lines, string $linkLabel, string $path): void
    {
        self::afterResponse(static function () use ($familyId, $lines, $linkLabel, $path): void {
            self::toFamily($familyId, static fn(string $base): string => self::personalText($lines, $linkLabel, $base, $path));
        });
    }

    /**
     * Текст личного уведомления: строки, пустая строка и ссылка — диплинк
     * мини-приложения своей платформы. Отдельно от отправки — для тестов.
     *
     * @param array<int,string> $lines
     */
    public static function personalText(array $lines, string $linkLabel, string $base, string $path): string
    {
        return implode("\n", [...$lines, '', $linkLabel . self::deepLink($base, $path)]);
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
        ['tgToken' => $token, 'tgChat' => $chat, 'tgBase' => $tgBase,
         'maxToken' => $mToken, 'maxChat' => $mChat, 'maxBase' => $mBase] = self::groupTargets();

        if ($token !== '' && $chat !== '' && !TelegramBot::sendMessage($token, $chat, $text($tgBase))) {
            error_log('BotNotify: сообщение не ушло в Telegram-группу ' . $chat);
        }
        if ($mToken !== '' && $mChat !== '' && $mBase !== '' && !MaxBot::sendToChat($mToken, $mChat, $text($mBase))) {
            error_log('BotNotify: сообщение не ушло в группу MAX ' . $mChat);
        }
    }

    /**
     * То же, но ссылка уезжает из текста в кнопку под сообщением: в Telegram это
     * inline-кнопка с диплинком (нажатие открывает мини-приложение сразу на нужной
     * странице). В MAX кнопок под сообщением не шлём — там ссылка остаётся в тексте.
     *
     * $text() получает сообщение без ссылки, $path — путь внутри /poselenie/.
     */
    public static function toGroupWithButton(callable $text, string $buttonLabel, string $path): void
    {
        ['tgToken' => $token, 'tgChat' => $chat, 'tgBase' => $tgBase,
         'maxToken' => $mToken, 'maxChat' => $mChat, 'maxBase' => $mBase] = self::groupTargets();

        if ($token !== '' && $chat !== '') {
            $markup = (string) json_encode([
                'inline_keyboard' => [[['text' => $buttonLabel, 'url' => self::deepLink($tgBase, $path)]]],
            ], JSON_UNESCAPED_UNICODE);
            if (!TelegramBot::sendMessage($token, $chat, $text(), null, $markup)) {
                error_log('BotNotify: сообщение не ушло в Telegram-группу ' . $chat);
            }
        }
        if ($mToken !== '' && $mChat !== '' && $mBase !== '') {
            $withLink = $text() . "\n\n" . 'Открыть: ' . self::deepLink($mBase, $path);
            if (!MaxBot::sendToChat($mToken, $mChat, $withLink)) {
                error_log('BotNotify: сообщение не ушло в группу MAX ' . $mChat);
            }
        }
    }

    /** Токены, чаты и ссылки ботов обеих платформ — один разбор настроек на всех. */
    private static function groupTargets(): array
    {
        $tg  = Config::get('telegram');
        $max = Config::get('max');
        return [
            'tgToken'  => is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '',
            'tgChat'   => is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '',
            'tgBase'   => self::appBase('tg'),
            'maxToken' => is_array($max) ? (string) ($max['bot_token'] ?? '') : '',
            'maxChat'  => is_array($max) ? (string) ($max['group_chat_id'] ?? '') : '',
            'maxBase'  => self::appBase('max'),
        ];
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
