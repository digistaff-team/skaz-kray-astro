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
    /** Темы писем заказчикам, чьи просьбы ушли на доску из-за поездки. */
    public const TRIP_CANCELLED_SUBJECT = 'Поездка отменена — заявка на общей доске';
    public const DONE_TRIP_SUBJECT = 'Водитель не ответил — заявка на общей доске';

    /** @return array<int,string> */
    public static function requestLines(array $d): array
    {
        $lines = ['📦 Просьба привезти', '', ...self::summary($d), 'Заказчик: ' . $d['req_name']];
        if (($d['origin'] ?? null) !== null && ($d['destination'] ?? null) !== null) {
            $lines[] = 'Поездка: ' . $d['origin'] . ' → ' . $d['destination'];
        }
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
        $lines = ['📦 Привезли', '', ...self::summary($d)];
        if (($d['car_name'] ?? '') !== '') { $lines[] = 'Исполнитель: ' . $d['car_name']; }
        if (($d['receipt_sum'] ?? null) !== null && $d['receipt_sum'] !== '') {
            $lines[] = 'Сумма по чеку: ' . buy_money((string) $d['receipt_sum']) . ' ₽';
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

    /** Поездка отмечена состоявшейся, а водитель так и не ответил на просьбу. @return array<int,string> */
    public static function doneTripLines(array $d): array
    {
        return ['Водитель не ответил — заявка на общей доске', '', ...self::summary($d)];
    }

    /**
     * Письмо (если адрес есть) и бот. Звать последним в обработчике.
     * @param array<int,string> $lines
     */
    public static function send(int $familyId, ?string $email, string $subject, array $lines, string $linkLabel, string $path): void
    {
        self::queueEmail($email, $subject, $lines, $linkLabel, $path);
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
            self::queueEmail($it['email'] ?? null, $subject, $it['lines'], $linkLabel, $path);
        }
        BotNotify::afterResponse(static function () use ($items, $linkLabel, $path): void {
            foreach ($items as $it) {
                BotNotify::toFamily((int) $it['family_id'],
                    static fn(string $base): string => BotNotify::personalText($it['lines'], $linkLabel, $base, $path));
            }
        });
    }

    /**
     * Письмо в очередь Mailer, если адрес есть. Отдельно от send() — так проверяется
     * тестами без обращения к BotNotify (тот трогает БД и сеть).
     * @param array<int,string> $lines
     */
    public static function queueEmail(?string $email, string $subject, array $lines, string $linkLabel, string $path): void
    {
        if (($email ?? '') === '') { return; }
        Mailer::later($email, $subject . ' — Сказочный Край', self::emailBody($lines, $linkLabel, $path));
    }

    /** Текст письма: строки уведомления, пустая строка, ссылка. @param array<int,string> $lines */
    public static function emailBody(array $lines, string $linkLabel, string $path): string
    {
        return implode("\n", $lines) . "\n\n" . $linkLabel . \SkazResidents\Config::get('base_url') . $path;
    }

    /** «Купить: …», «Где: …», «К какому дню: …» — общая часть всех сообщений. @return array<int,string> */
    private static function summary(array $d): array
    {
        $lines = [
            delivery_kind_label((string) $d['kind']) . ': ' . self::oneLine((string) $d['what']),
            'Где: ' . self::oneLine((string) $d['place']),
        ];
        if (($d['need_by'] ?? null) !== null && $d['need_by'] !== '') {
            $lines[] = 'К какому дню: ' . ru_date((string) $d['need_by']);
        }
        return $lines;
    }

    /** Свободный текст (может прийти с переносами строк) — в одну строку, до 160 знаков. */
    private static function oneLine(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_strimwidth($s, 0, 160, '…');
    }
}
