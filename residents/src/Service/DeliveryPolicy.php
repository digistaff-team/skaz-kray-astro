<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Правила заявки на доставку: что может житель с заявкой в её текущем статусе.
 * Без базы и сессии — один источник правды и для кнопок в шаблоне, и для
 * проверки каждого POST в DeliveryController. Спека:
 * docs/superpowers/specs/2026-09-26-dostavka-design.md, «Жизненный цикл».
 */
final class DeliveryPolicy
{
    public const TAKE        = 'take';         // «Возьму»
    // DECLINE и DROP — одна кнопка «Не смогу» с точки зрения жителя; контроллер выбирает
    // действие по статусу заявки (requested → DECLINE, accepted → DROP).
    public const DECLINE     = 'decline';      // водитель: «Не смогу» на просьбу к поездке
    public const DROP        = 'drop';         // исполнитель: «Не смогу» после «Возьму»
    public const UNASSIGN    = 'unassign';     // заказчик: «Отказаться от исполнителя»
    public const DELIVER     = 'deliver';      // «Привёз»
    public const ADD_RECEIPT = 'add_receipt';  // «Добавить фото чека»
    public const SETTLE      = 'settle';       // «Получил, рассчитались»
    public const TO_BOARD    = 'to_board';     // «Выложить на доску»
    public const CANCEL      = 'cancel';       // «Отменить»

    public const MAX_RECEIPTS = 3;

    /**
     * $me и $tripDriverId — настоящие int (из PDO приводить через (int)); $tripDriverId = null,
     * если заявка не к поездке.
     *
     * @param array<string,mixed> $d        заявка (нужны requester_id, carrier_id, trip_id, kind, status)
     * @param int|null            $tripDriverId водитель поездки заявки, если она к поездке
     * @return array<int,string>
     */
    public static function actions(array $d, int $me, ?int $tripDriverId, int $receiptCount = 0): array
    {
        $isRequester = (int) $d['requester_id'] === $me;
        $isCarrier   = $d['carrier_id'] !== null && (int) $d['carrier_id'] === $me;
        $isDriver    = $d['trip_id'] !== null && $tripDriverId !== null && $tripDriverId === $me;

        return match ((string) $d['status']) {
            'requested' => $isRequester ? [self::CANCEL] : ($isDriver ? [self::TAKE, self::DECLINE] : []),
            'open'      => $isRequester ? [self::CANCEL] : [self::TAKE],
            'accepted'  => $isRequester ? [self::UNASSIGN, self::CANCEL] : ($isCarrier ? [self::DROP, self::DELIVER] : []),
            'delivered' => $isRequester ? [self::SETTLE]
                : (($isCarrier && $d['kind'] === 'buy' && $receiptCount < self::MAX_RECEIPTS) ? [self::ADD_RECEIPT] : []),
            'declined'  => $isRequester ? [self::TO_BOARD] : [],
            default     => [],
        };
    }

    /** @param array<string,mixed> $d */
    public static function allows(string $action, array $d, int $me, ?int $tripDriverId, int $receiptCount = 0): bool
    {
        return in_array($action, self::actions($d, $me, $tripDriverId, $receiptCount), true);
    }

    /**
     * Сторона заявки для отказа в действии: сторона получает «Заявка уже обработана»
     * (кнопка у неё была, статус ушёл), не сторона — 403. Открытую заявку взять может
     * любой, поэтому для TAKE на открытой любой житель — сторона; для прочих действий нет.
     *
     * @param array<string,mixed> $d
     */
    public static function isParty(array $d, int $me, ?int $tripDriverId, string $action): bool
    {
        return self::isSide($d, $me, $tripDriverId)
            || ((string) $d['status'] === 'open' && $action === self::TAKE);
    }

    /**
     * Кто видит карточку: открытую — любой житель (она на доске), остальные — только
     * стороны (заказчик, текущий исполнитель, водитель поездки). Прочим — «не найдена».
     *
     * @param array<string,mixed> $d
     */
    public static function canView(array $d, int $me, ?int $tripDriverId): bool
    {
        return (string) $d['status'] === 'open' || self::isSide($d, $me, $tripDriverId);
    }

    /** @param array<string,mixed> $d */
    private static function isSide(array $d, int $me, ?int $tripDriverId): bool
    {
        return (int) $d['requester_id'] === $me
            || ($d['carrier_id'] !== null && (int) $d['carrier_id'] === $me)
            || ($d['trip_id'] !== null && $tripDriverId !== null && $tripDriverId === $me);
    }

    /**
     * Код получения и фото чека — только сторонам заявки: заказчику и назначенному
     * исполнителю. Снятый исполнитель перестаёт видеть сразу: carrier_id обнулён.
     *
     * @param array<string,mixed> $d
     */
    public static function seesPrivate(array $d, int $me): bool
    {
        return (int) $d['requester_id'] === $me
            || ($d['carrier_id'] !== null && (int) $d['carrier_id'] === $me);
    }
}
