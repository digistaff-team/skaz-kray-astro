<?php
declare(strict_types=1);
namespace SkazResidents\Service;


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

    /** Анонс новой записи в дневниках поместий. */
    public static function diary(int $id, string $title, ?string $author): void
    {
        $head = '📔 Новая запись в дневниках поместий';
        $line = '«' . $title . '»' . (($author ?? '') !== '' ? ' · ' . $author : '');
        self::announce($head, $line, '/poselenie/dnevniki/' . $id);
    }

    /** Анонс новой поездки (попутки), опубликованной водителем. */
    public static function trip(int $id, string $origin, string $destination, string $date, string $time, int $seats): void
    {
        self::announce('🚗 Новая поездка', self::tripLine($origin, $destination, $date, $time, $seats), '/poselenie/poezdki/' . $id);
    }

    /**
     * Строка поездки: маршрут, когда и сколько мест. Дату показываем по-русски
     * (ru_date из bootstrap; в CLI/тестах её может не быть — тогда как в БД).
     */
    public static function tripLine(string $origin, string $destination, string $date, string $time, int $seats): string
    {
        $when = function_exists('ru_date') ? ru_date($date) : $date;
        if ($time !== '') { $when .= ', ' . $time; }
        return $origin . ' → ' . $destination . ' · ' . $when . ' · мест: ' . $seats;
    }

    /** Анонс нового товара/услуги на Ярмарке (карточки внутри портала нет — ведём в ленту). */
    public static function product(int $id, string $title, ?string $price): void
    {
        $head = '🛒 Новое на Ярмарке';
        $line = '«' . $title . '»' . (($price ?? '') !== '' ? ' · ' . $price : '');
        self::announce($head, $line, '/poselenie/yarmarka');
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
        return BotNotify::deepLink($base, $path);
    }

    private static function announce(string $head, string $line, string $path): void
    {
        BotNotify::afterResponse(static function () use ($head, $line, $path): void {
            BotNotify::toGroup(static fn(string $base): string => self::text($head, $line, BotNotify::deepLink($base, $path)));
        });
    }

}
