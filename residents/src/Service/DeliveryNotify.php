<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\Mailer;

/**
 * Личные уведомления по заявкам на доставку: письмо (если есть почта) и сообщение
 * бота. *Lines() — только текст, для тестов. Код получения (pickup_code) и ссылки
 * на фото чека сюда не попадают никогда: они видны только в карточке заявки.
 *
 * send() — последним в обработчике: BotNotify::personal завершает ответ.
 */
final class DeliveryNotify
{
    /** @return array<int,string> */
    public static function requestLines(array $d): array
    {
        $lines = ['📦 Просьба привезти', '', ...self::summary($d), 'Заказчик: ' . $d['req_name']];
        if (($d['origin'] ?? null) !== null) { $lines[] = 'Поездка: ' . $d['origin'] . ' → ' . $d['destination']; }
        return $lines;
    }

    /** @return array<int,string> */
    public static function takenLines(array $d, string $carrierContact): array
    {
        return ['✅ Заявку взяли', '', ...self::summary($d), 'Исполнитель: ' . $carrierContact];
    }

    /** @return array<int,string> */
    public static function driverDeclinedLines(array $d): array
    {
        return ['🚫 Водитель не сможет привезти', '', ...self::summary($d)];
    }

    /** @return array<int,string> */
    public static function droppedLines(array $d): array
    {
        return ['↩️ Исполнитель не сможет — заявка снова на доске', '', ...self::summary($d)];
    }

    /** @return array<int,string> */
    public static function unassignedLines(array $d): array
    {
        return ['↩️ Заказчик снял вас с заявки', '', ...self::summary($d)];
    }

    /** @return array<int,string> */
    public static function deliveredLines(array $d, int $receiptPhotos): array
    {
        $lines = ['📦 Привезли', '', ...self::summary($d), 'Исполнитель: ' . ($d['car_name'] ?? '')];
        if (($d['receipt_sum'] ?? null) !== null && $d['receipt_sum'] !== '') {
            $lines[] = 'Сумма по чеку: ' . self::money((string) $d['receipt_sum']) . ' ₽';
        }
        if ($receiptPhotos > 0) { $lines[] = 'Фото чека — в карточке заявки'; }
        return $lines;
    }

    /** @return array<int,string> */
    public static function settledLines(array $d): array
    {
        return ['🤝 Получение и расчёт подтверждены', '', ...self::summary($d)];
    }

    /** @return array<int,string> */
    public static function cancelledLines(array $d): array
    {
        return ['🚫 Заявка отменена', '', ...self::summary($d)];
    }

    /** @return array<int,string> */
    public static function tripCancelledLines(array $d): array
    {
        return ['Поездка отменена — ваша заявка теперь на общей доске', '', ...self::summary($d)];
    }

    /**
     * Письмо (если адрес есть) и бот. Звать последним в обработчике.
     * @param array<int,string> $lines
     */
    public static function send(int $familyId, ?string $email, string $subject, array $lines, string $linkLabel, string $path): void
    {
        if (($email ?? '') !== '') {
            Mailer::later((string) $email, $subject . ' — Сказочный Край',
                implode("\n", $lines) . "\n\n" . $linkLabel . \SkazResidents\Config::get('base_url') . $path);
        }
        BotNotify::personal($familyId, $lines, $linkLabel, $path);
    }

    /**
     * Несколько адресатов разом (отмена поездки): письма в очередь, бот — одним
     * afterResponse на всю пачку, как PurchaseNotify::toMany.
     * @param array<int,array{family_id:int,email:?string,lines:array<int,string>}> $items
     */
    public static function sendMany(string $subject, array $items, string $linkLabel, string $path): void
    {
        if (!$items) { return; }
        foreach ($items as $it) {
            if (($it['email'] ?? '') !== '') {
                Mailer::later((string) $it['email'], $subject . ' — Сказочный Край',
                    implode("\n", $it['lines']) . "\n\n" . $linkLabel . \SkazResidents\Config::get('base_url') . $path);
            }
        }
        BotNotify::afterResponse(static function () use ($items, $linkLabel, $path): void {
            foreach ($items as $it) {
                BotNotify::toFamily((int) $it['family_id'],
                    static fn(string $base): string => BotNotify::personalText($it['lines'], $linkLabel, $base, $path));
            }
        });
    }

    /** «Купить: …», «Где: …», «К какому дню: …» — общая часть всех сообщений. @return array<int,string> */
    private static function summary(array $d): array
    {
        $what = trim(preg_replace('/\s+/u', ' ', (string) $d['what']) ?? '');
        $lines = [
            delivery_kind_label((string) $d['kind']) . ': ' . mb_strimwidth($what, 0, 160, '…'),
            'Где: ' . $d['place'],
        ];
        if (($d['need_by'] ?? null) !== null && $d['need_by'] !== '') {
            $lines[] = 'К какому дню: ' . ru_date((string) $d['need_by']);
        }
        return $lines;
    }

    private static function money(string $v): string
    {
        return function_exists('buy_money') ? buy_money($v) : $v;
    }
}
