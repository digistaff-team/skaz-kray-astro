<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Личные уведомления участникам проката книг от @SkazKray_bot — в дополнение к
 * письмам, которые раздел слал и раньше. Это именно личка, а не группа жителей:
 * подробности сделки касаются только владельца и читателя.
 *
 * Бот пишет тем, у кого привязан Telegram/MAX и кто разрешил боту писать в личку
 * (см. BotNotify::toFamily). Кто не получил сообщение — получит письмо, как и
 * раньше; уведомления идут после ответа и ничего не блокируют.
 */
final class LoanNotify
{
    /** Читатель забронировал книгу → владельцу. */
    public static function bookRequested(int $ownerId, string $bookTitle, string $borrower, ?string $due): void
    {
        $lines = ['📚 Новая бронь книги', '', '«' . $bookTitle . '»', 'Читатель: ' . $borrower];
        if (($due ?? '') !== '') { $lines[] = 'Желаемый срок: до ' . self::date($due); }
        self::send($ownerId, $lines, 'Одобрить или отклонить: ', '/poselenie/knigi/moi');
    }

    /** Читатель снял свою бронь → владельцу. */
    public static function bookCancelled(int $ownerId, string $bookTitle, string $borrower): void
    {
        self::send($ownerId, ['📚 Бронь отменена', '', '«' . $bookTitle . '»', 'Читатель: ' . $borrower],
            'Мои книги: ', '/poselenie/knigi/moi');
    }

    /** Владелец одобрил бронь и выдал книгу → читателю. */
    public static function bookGiven(int $borrowerId, string $bookTitle, string $owner): void
    {
        self::send($borrowerId, ['✅ Бронь одобрена', '', '«' . $bookTitle . '»', 'Владелец: ' . $owner,
            'Договоритесь о передаче, после прочтения верните книгу.'], 'Мои книги: ', '/poselenie/knigi/moi');
    }

    /** Владелец отклонил бронь → читателю. */
    public static function bookDeclined(int $borrowerId, string $bookTitle): void
    {
        self::send($borrowerId, ['🚫 Бронь отклонена', '', '«' . $bookTitle . '»',
            'Владелец не смог дать книгу сейчас.'], 'Каталог книг: ', '/poselenie/knigi');
    }

    /** Владелец принял возврат → читателю (владелец отмечает возврат сам и так о нём знает). */
    public static function bookReturned(int $borrowerId, string $bookTitle, bool $broken): void
    {
        $lines = ['📗 Возврат принят', '', '«' . $bookTitle . '»'];
        $lines[] = $broken ? 'Владелец отметил, что книга требует внимания.' : 'Спасибо! Книга снова в каталоге.';
        self::send($borrowerId, $lines, 'Каталог книг: ', '/poselenie/knigi');
    }

    /** Собрать текст и отправить лично семье (после ответа пользователю). */
    private static function send(int $familyId, array $lines, string $linkLabel, string $path): void
    {
        BotNotify::afterResponse(static function () use ($familyId, $lines, $linkLabel, $path): void {
            BotNotify::toFamily($familyId, static function (string $base) use ($lines, $linkLabel, $path): string {
                return implode("\n", [...$lines, '', $linkLabel . BotNotify::deepLink($base, $path)]);
            });
        });
    }

    /** "2026-10-12" → "12 октября 2026" (ru_date из bootstrap; в CLI — как есть). */
    private static function date(string $ymd): string
    {
        return function_exists('ru_date') ? ru_date($ymd) : $ymd;
    }
}
