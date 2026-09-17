<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Личные уведомления по совместным закупкам от @SkazKray_bot.
 *
 * Организатору — про состав пула (кто записался, кто вышел), участникам — про
 * две стадии, которые требуют от них действий: заказ ушёл поставщику и товар
 * приехал. Отмену тоже сообщаем: человек мог рассчитывать на этот заказ.
 *
 * В группу жителей это не идёт — там только анонс новой закупки
 * ({@see CatalogAnnounce::purchase}), остальное касается участников.
 */
final class PurchaseNotify
{
    /** Новый участник записался → организатору. */
    public static function joined(int $organizerId, string $title, string $who, string $qty, string $unit): void
    {
        self::toOne($organizerId, [
            '🛒 Новый участник закупки',
            '',
            '«' . $title . '»',
            $who . ' — ' . self::qty($qty) . ' ' . $unit,
        ], 'Состав пула: ');
    }

    /** Участник вышел из закупки → организатору. */
    public static function left(int $organizerId, string $title, string $who): void
    {
        self::toOne($organizerId, [
            '🛒 Участник вышел из закупки',
            '',
            '«' . $title . '»',
            $who . ' снял заявку',
        ], 'Состав пула: ');
    }

    /** Заказ отправлен поставщику → всем участникам. */
    public static function ordered(array $familyIds, string $title): void
    {
        self::toMany($familyIds, [
            '📦 Закупка заказана',
            '',
            '«' . $title . '»',
            'Заказ отправлен поставщику, состав больше не меняется.',
        ]);
    }

    /** Товар приехал → всем участникам, с местом выдачи. */
    public static function arrived(array $familyIds, string $title, ?string $pickup): void
    {
        $lines = ['🎉 Привезли, можно забирать', '', '«' . $title . '»'];
        if (($pickup ?? '') !== '') { $lines[] = 'Где забрать: ' . $pickup; }
        self::toMany($familyIds, $lines);
    }

    /** Закупка отменена → всем участникам. */
    public static function cancelled(array $familyIds, string $title): void
    {
        self::toMany($familyIds, [
            '🚫 Закупка отменена',
            '',
            '«' . $title . '»',
            'Организатор остановил сбор.',
        ]);
    }

    // --- helpers ---

    /** @param array<int,string> $lines */
    private static function toOne(int $familyId, array $lines, string $linkLabel = 'Открыть: '): void
    {
        BotNotify::afterResponse(static function () use ($familyId, $lines, $linkLabel): void {
            BotNotify::toFamily($familyId, static fn(string $base): string => self::text($lines, $linkLabel, $base));
        });
    }

    /**
     * Рассылка участникам. Один afterResponse на всю пачку: он закрывает сессию и
     * отпускает браузер один раз, а не на каждого получателя.
     * @param array<int,int> $familyIds  @param array<int,string> $lines
     */
    private static function toMany(array $familyIds, array $lines): void
    {
        if (!$familyIds) { return; }
        BotNotify::afterResponse(static function () use ($familyIds, $lines): void {
            foreach ($familyIds as $id) {
                BotNotify::toFamily((int) $id, static fn(string $base): string => self::text($lines, 'Закупка: ', $base));
            }
        });
    }

    /** @param array<int,string> $lines */
    private static function text(array $lines, string $linkLabel, string $base): string
    {
        return implode("\n", [...$lines, '', $linkLabel . BotNotify::deepLink($base, '/poselenie/zakupki')]);
    }

    /** «5.00» → «5», «2.50» → «2.5» — в сообщении хвост нулей лишний. */
    private static function qty(string $qty): string
    {
        return rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.');
    }
}
